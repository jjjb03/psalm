<?php

declare(strict_types=1);

namespace Psalm\Internal\LanguageServer;

use AdvancedJsonRpc\Dispatcher;
use AdvancedJsonRpc\Error;
use AdvancedJsonRpc\ErrorCode;
use AdvancedJsonRpc\ErrorResponse;
use AdvancedJsonRpc\Request;
use AdvancedJsonRpc\Response;
use AdvancedJsonRpc\SuccessResponse;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Generator;
use InvalidArgumentException;
use LanguageServerProtocol\ClientCapabilities;
use LanguageServerProtocol\ClientInfo;
use LanguageServerProtocol\CodeDescription;
use LanguageServerProtocol\CompletionOptions;
use LanguageServerProtocol\Diagnostic;
use LanguageServerProtocol\DiagnosticSeverity;
use LanguageServerProtocol\ExecuteCommandOptions;
use LanguageServerProtocol\InitializeResult;
use LanguageServerProtocol\InitializeResultServerInfo;
use LanguageServerProtocol\LogMessage;
use LanguageServerProtocol\MessageType;
use LanguageServerProtocol\Position;
use LanguageServerProtocol\Range;
use LanguageServerProtocol\SaveOptions;
use LanguageServerProtocol\ServerCapabilities;
use LanguageServerProtocol\SignatureHelpOptions;
use LanguageServerProtocol\TextDocumentSyncKind;
use LanguageServerProtocol\TextDocumentSyncOptions;
use Psalm\Config;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Composer;
use Psalm\Internal\LanguageServer\Server\TextDocument as ServerTextDocument;
use Psalm\Internal\LanguageServer\Server\Workspace as ServerWorkspace;
use Psalm\Internal\Provider\ClassLikeStorageCacheProvider;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Internal\Provider\FileReferenceCacheProvider;
use Psalm\Internal\Provider\FileStorageCacheProvider;
use Psalm\Internal\Provider\ParserCacheProvider;
use Psalm\Internal\Provider\ProjectCacheProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\IssueBuffer;
use Throwable;

use function Amp\asyncCoroutine;
use function Amp\call;
use function array_combine;
use function array_filter;
use function array_keys;
use function array_map;
use function array_reduce;
use function array_shift;
use function array_unshift;
use function array_values;
use function cli_set_process_title;
use function explode;
use function extension_loaded;
use function fwrite;
use function implode;
use function in_array;
use function ini_get;
use function json_encode;
use function max;
use function parse_url;
use function pcntl_fork;
use function rawurlencode;
use function realpath;
use function str_replace;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_server;
use function strpos;
use function substr;
use function trim;
use function urldecode;

use const JSON_PRETTY_PRINT;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * @internal
 */
class LanguageServer extends Dispatcher
{
    /**
     * Handles textDocument/* method calls
     *
     * @var ?ServerTextDocument
     */
    public $textDocument;

    /**
     * Handles workspace/* method calls
     *
     * @var ?ServerWorkspace
     */
    public $workspace;

    /**
     * @var ProtocolReader
     */
    protected $protocolReader;

    /**
     * @var ProtocolWriter
     */
    protected $protocolWriter;

    /**
     * @var LanguageClient
     */
    public $client;

    /**
     * @var ClientCapabilities|null
     */
    public $clientCapabilities;

    /**
     * @var string|null
     */
    public $trace;

    /**
     * @var ProjectAnalyzer
     */
    protected $project_analyzer;

    /**
     * @var Codebase
     */
    protected $codebase;

    /**
     * The AMP Delay token
     * @var string
     */
    protected $versionedAnalysisDelayToken = '';

    public function __construct(
        ProtocolReader $reader,
        ProtocolWriter $writer,
        ProjectAnalyzer $project_analyzer,
        Codebase $codebase,
        ClientConfiguration $clientConfiguration,
        Progress $progress
    ) {
        parent::__construct($this, '/');

        $progress->setServer($this);

        $this->project_analyzer = $project_analyzer;

        $this->codebase = $codebase;

        $this->protocolWriter = $writer;

        $this->protocolReader = $reader;
        $this->protocolReader->on(
            'close',
            function (): void {
                $this->shutdown();
                $this->exit();
            }
        );
        $this->protocolReader->on(
            'message',
            asyncCoroutine(
                /**
                 * @return Generator<int, Promise, mixed, void>
                 */
                function (Message $msg): Generator {
                    if (!$msg->body) {
                        return;
                    }

                    // Ignore responses, this is the handler for requests and notifications
                    if (Response::isResponse($msg->body)) {
                        return;
                    }

                    $result = null;
                    $error = null;
                    try {
                        // Invoke the method handler to get a result
                        /**
                         * @var Promise|null
                         */
                        $dispatched = $this->dispatch($msg->body);
                        if ($dispatched !== null) {
                            /** @psalm-suppress MixedAssignment */
                            $result = yield $dispatched;
                        } else {
                            $result = null;
                        }
                    } catch (Error $e) {
                        // If a ResponseError is thrown, send it back in the Response
                        $error = $e;
                    } catch (Throwable $e) {
                        // If an unexpected error occurred, send back an INTERNAL_ERROR error response
                        $error = new Error(
                            (string) $e,
                            ErrorCode::INTERNAL_ERROR,
                            null,
                            $e
                        );
                    }
                    if ($error !== null) {
                        $this->logError($error->message);
                    }
                    // Only send a Response for a Request
                    // Notifications do not send Responses
                    /**
                     * @psalm-suppress UndefinedPropertyFetch
                     * @psalm-suppress MixedArgument
                     */
                    if (Request::isRequest($msg->body)) {
                        if ($error !== null) {
                            $responseBody = new ErrorResponse($msg->body->id, $error);
                        } else {
                            $responseBody = new SuccessResponse($msg->body->id, $result);
                        }
                        yield $this->protocolWriter->write(new Message($responseBody));
                    }
                }
            )
        );

        $this->protocolReader->on(
            'readMessageGroup',
            function (): void {
                //$this->verboseLog('Received message group');
                //$this->doAnalysis();
            }
        );

        $this->client = new LanguageClient($reader, $writer, $this, $clientConfiguration);

        $this->logInfo("Psalm Language Server ".PSALM_VERSION." has started.");
    }

    /**
     * Start the Server
     */
    public static function run(Config $config, ClientConfiguration $clientConfiguration, string $base_dir): void
    {
        $progress = new Progress();

        //no-cache mode does not work in the LSP
        $providers = new Providers(
            new FileProvider,
            new ParserCacheProvider($config),
            new FileStorageCacheProvider($config),
            new ClassLikeStorageCacheProvider($config),
            new FileReferenceCacheProvider($config),
            new ProjectCacheProvider(Composer::getLockFilePath($base_dir))
        );

        $codebase = new Codebase(
            $config,
            $providers,
            $progress
        );

        if ($config->find_unused_variables) {
            $codebase->reportUnusedVariables();
        }

        if ($clientConfiguration->findUnusedCode) {
            $codebase->reportUnusedCode($clientConfiguration->findUnusedCode);
        }

        $project_analyzer = new ProjectAnalyzer(
            $config,
            $providers,
            null,
            [],
            1,
            $progress,
            $codebase
        );

        if ($clientConfiguration->onchangeLineLimit) {
            $project_analyzer->onchange_line_limit = $clientConfiguration->onchangeLineLimit;
        }

        //Setup Project Analyzer
        $project_analyzer->provide_completion = (bool) $clientConfiguration->provideCompletion;

        @cli_set_process_title('Psalm ' . PSALM_VERSION . ' - PHP Language Server');

        if (!$clientConfiguration->TCPServerMode && $clientConfiguration->TCPServerAddress) {
            // Connect to a TCP server
            $socket = stream_socket_client('tcp://' . $clientConfiguration->TCPServerAddress, $errno, $errstr);
            if ($socket === false) {
                fwrite(STDERR, "Could not connect to language client. Error $errno\n$errstr");
                exit(1);
            }
            stream_set_blocking($socket, false);
            new self(
                new ProtocolStreamReader($socket),
                new ProtocolStreamWriter($socket),
                $project_analyzer,
                $codebase,
                $clientConfiguration,
                $progress
            );
            Loop::run();
        } elseif ($clientConfiguration->TCPServerMode && $clientConfiguration->TCPServerAddress) {
            // Run a TCP Server
            $tcpServer = stream_socket_server('tcp://' . $clientConfiguration->TCPServerAddress, $errno, $errstr);
            if ($tcpServer === false) {
                fwrite(STDERR, "Could not listen on {$clientConfiguration->TCPServerAddress}. Error $errno\n$errstr");
                exit(1);
            }
            fwrite(STDOUT, "Server listening on {$clientConfiguration->TCPServerAddress}\n");

            $fork_available = true;
            if (!extension_loaded('pcntl')) {
                fwrite(STDERR, "PCNTL is not available. Only a single connection will be accepted\n");
                $fork_available = false;
            }

            $disabled_functions = array_map('trim', explode(',', ini_get('disable_functions')));
            if (in_array('pcntl_fork', $disabled_functions)) {
                fwrite(
                    STDERR,
                    "pcntl_fork() is disabled by php configuration (disable_functions directive)."
                    . " Only a single connection will be accepted\n"
                );
                $fork_available = false;
            }

            while ($socket = stream_socket_accept($tcpServer, -1)) {
                fwrite(STDOUT, "Connection accepted\n");
                stream_set_blocking($socket, false);
                if ($fork_available) {
                    // If PCNTL is available, fork a child process for the connection
                    // An exit notification will only terminate the child process
                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        fwrite(STDERR, "Could not fork\n");
                        exit(1);
                    }

                    if ($pid === 0) {
                        // Child process
                        $reader = new ProtocolStreamReader($socket);
                        $reader->on(
                            'close',
                            function (): void {
                                fwrite(STDOUT, "Connection closed\n");
                            }
                        );
                        new self(
                            $reader,
                            new ProtocolStreamWriter($socket),
                            $project_analyzer,
                            $codebase,
                            $clientConfiguration,
                            $progress
                        );
                        // Just for safety
                        exit(0);
                    }
                } else {
                    // If PCNTL is not available, we only accept one connection.
                    // An exit notification will terminate the server
                    new LanguageServer(
                        new ProtocolStreamReader($socket),
                        new ProtocolStreamWriter($socket),
                        $project_analyzer,
                        $codebase,
                        $clientConfiguration,
                        $progress
                    );
                    Loop::run();
                }
            }
        } else {
            // Use STDIO
            stream_set_blocking(STDIN, false);
            new LanguageServer(
                new ProtocolStreamReader(STDIN),
                new ProtocolStreamWriter(STDOUT),
                $project_analyzer,
                $codebase,
                $clientConfiguration,
                $progress
            );
            Loop::run();
        }
    }

    /**
     * The initialize request is sent as the first request from the client to the server.
     *
     * @param ClientCapabilities $capabilities The capabilities provided by the client (editor)
     * @param int|null $processId The process Id of the parent process that started the server.
     * Is null if the process has not been started by another process. If the parent process is
     * not alive then the server should exit (see exit notification) its process.
     * @param ClientInfo|null $clientInfo Information about the client
     * @param string|null $locale  The locale the client is currently showing the user interface
     * in. This must not necessarily be the locale of the operating
     * system.
     * @param string|null $rootPath The rootPath of the workspace. Is null if no folder is open.
     * @param mixed $initializationOptions
     * @param string|null $trace The initial trace setting. If omitted trace is disabled ('off').
     * @param array|null $workspaceFolders The workspace folders configured in the client when
     * the server starts. This property is only available if the client supports workspace folders.
     * It can be `null` if the client supports workspace folders but none are
     * configured.
     * @psalm-return Promise<InitializeResult>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function initialize(
        ClientCapabilities $capabilities,
        ?int $processId = null,
        ?ClientInfo $clientInfo = null,
        ?string $locale = null,
        ?string $rootPath = null,
        ?string $rootUri = null,
        $initializationOptions = null,
        ?string $trace = null
        //?array $workspaceFolders = null //error in json-dispatcher
    ): Promise {
        $this->clientCapabilities = $capabilities;
        $this->trace = $trace;
        return call(
            /** @return Generator<int, true, mixed, InitializeResult> */
            function () {
                $this->logInfo("Initializing...");
                $this->clientStatus('initializing');

                // Eventually, this might block on something. Leave it as a generator.
                /** @psalm-suppress TypeDoesNotContainType */
                if (false) {
                    yield true;
                }

                $this->project_analyzer->serverMode($this);

                $this->logInfo("Initializing: Getting code base...");
                $this->clientStatus('initializing', 'getting code base');

                $this->logInfo("Initializing: Scanning files ({$this->project_analyzer->threads} Threads)...");
                $this->clientStatus('initializing', 'scanning files');
                $this->codebase->scanFiles($this->project_analyzer->threads);

                $this->logInfo("Initializing: Registering stub files...");
                $this->clientStatus('initializing', 'registering stub files');
                $this->codebase->config->visitStubFiles($this->codebase, $this->project_analyzer->progress);

                if ($this->textDocument === null) {
                    $this->textDocument = new ServerTextDocument(
                        $this,
                        $this->codebase,
                        $this->project_analyzer
                    );
                }

                if ($this->workspace === null) {
                    $this->workspace = new ServerWorkspace(
                        $this,
                        $this->codebase,
                        $this->project_analyzer
                    );
                }

                $serverCapabilities = new ServerCapabilities();

                //The server provides execute command support.
                $serverCapabilities->executeCommandProvider = new ExecuteCommandOptions(['test']);

                $textDocumentSyncOptions = new TextDocumentSyncOptions();

                //Open and close notifications are sent to the server.
                $textDocumentSyncOptions->openClose = true;

                $saveOptions = new SaveOptions();
                //The client is supposed to include the content on save.
                $saveOptions->includeText = true;
                $textDocumentSyncOptions->save = $saveOptions;

                /**
                 * Change notifications are sent to the server. See
                 * TextDocumentSyncKind.None, TextDocumentSyncKind.Full and
                 * TextDocumentSyncKind.Incremental. If omitted it defaults to
                 * TextDocumentSyncKind.None.
                 */
                if ($this->project_analyzer->onchange_line_limit === 0) {
                    /**
                     * Documents should not be synced at all.
                     */
                    $textDocumentSyncOptions->change = TextDocumentSyncKind::NONE;
                } else {
                    /**
                     * Documents are synced by always sending the full content
                     * of the document.
                     */
                    $textDocumentSyncOptions->change = TextDocumentSyncKind::FULL;
                }

                /**
                 * Defines how text documents are synced. Is either a detailed structure
                 * defining each notification or for backwards compatibility the
                 * TextDocumentSyncKind number. If omitted it defaults to
                 * `TextDocumentSyncKind.None`.
                 */
                $serverCapabilities->textDocumentSync = $textDocumentSyncOptions;

                /**
                 * The server provides document symbol support.
                 * Support "Find all symbols"
                 */
                $serverCapabilities->documentSymbolProvider = false;
                /**
                 * The server provides workspace symbol support.
                 * Support "Find all symbols in workspace"
                 */
                $serverCapabilities->workspaceSymbolProvider = false;
                /**
                 * The server provides goto definition support.
                 * Support "Go to definition"
                 */
                $serverCapabilities->definitionProvider = true;
                /**
                 * The server provides find references support.
                 * Support "Find all references"
                 */
                $serverCapabilities->referencesProvider = false;
                /**
                 * The server provides hover support.
                 * Support "Hover"
                 */
                $serverCapabilities->hoverProvider = true;

                /**
                 * The server provides completion support.
                 * Support "Completion"
                 */
                if ($this->project_analyzer->provide_completion) {
                    $serverCapabilities->completionProvider = new CompletionOptions();
                    /**
                     * The server provides support to resolve additional
                     * information for a completion item.
                     */
                    $serverCapabilities->completionProvider->resolveProvider = false;
                    /**
                     * Most tools trigger completion request automatically without explicitly
                     * requesting it using a keyboard shortcut (e.g. Ctrl+Space). Typically they
                     * do so when the user starts to type an identifier. For example if the user
                     * types `c` in a JavaScript file code complete will automatically pop up
                     * present `console` besides others as a completion item. Characters that
                     * make up identifiers don't need to be listed here.
                     *
                     * If code complete should automatically be trigger on characters not being
                     * valid inside an identifier (for example `.` in JavaScript) list them in
                     * `triggerCharacters`.
                     */
                    $serverCapabilities->completionProvider->triggerCharacters = ['$', '>', ':',"[", "(", ",", " "];
                }

                /**
                 * Whether code action supports the `data` property which is
                 * preserved between a `textDocument/codeAction` and a
                 * `codeAction/resolve` request.
                 *
                 * Support "Code Actions" if we support data
                 *
                 * @since LSP 3.16.0
                 */
                if ($this->clientCapabilities &&
                    $this->clientCapabilities->textDocument &&
                    $this->clientCapabilities->textDocument->publishDiagnostics &&
                    $this->clientCapabilities->textDocument->publishDiagnostics->dataSupport
                ) {
                    $serverCapabilities->codeActionProvider = true;
                }

                /**
                 * The server provides signature help support.
                 */
                $serverCapabilities->signatureHelpProvider = new SignatureHelpOptions(['(', ',']);

                $this->logInfo("Initializing: Complete.");
                $this->clientStatus('initialized');

                /**
                 * Information about the server.
                 *
                 * @since LSP 3.15.0
                 */
                $initializeResultServerInfo = new InitializeResultServerInfo('Psalm Language Server', PSALM_VERSION);

                return new InitializeResult($serverCapabilities, $initializeResultServerInfo);
            }
        );
    }

    /**
     * The initialized notification is sent from the client to the server after the client received the result of the
     * initialize request but before the client is sending any other request or notification to the server.
     * The server can use the initialized notification for example to dynamically register capabilities.
     * The initialized notification may only be sent once.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function initialized(): void
    {
        try {
            $this->client->refreshConfiguration();
        } catch (Throwable $e) {
            $this->logError((string) $e);
        }
        $this->clientStatus('running');
    }

    /**
     * Queue Change File Analysis
     * @param string $file_path
     * @param string $uri
     */
    public function queueChangeFileAnalysis(string $file_path, string $uri, ?int $version = null): void
    {
        $this->doVersionedAnalysisDebounce([$file_path => $uri], $version);
    }

    /**
     * Queue Open File Analysis
     * @param string $file_path
     * @param string $uri
     */
    public function queueOpenFileAnalysis(string $file_path, string $uri, ?int $version = null): void
    {
        $this->doVersionedAnalysis([$file_path => $uri], $version);
    }

    /**
     * Queue Closed File Analysis
     * @param string $file_path
     * @param string $uri
     */
    public function queueClosedFileAnalysis(string $file_path, string $uri): void
    {
        $this->doVersionedAnalysis([$file_path => $uri]);
    }

    /**
     * Queue Saved File Analysis
     * @param string $file_path
     * @param string $uri
     */
    public function queueSaveFileAnalysis(string $file_path, string $uri): void
    {
        $this->queueFileAnalysisWithOpenedFiles([$file_path => $uri]);
    }

    /**
     * Queue File Analysis appending any opened files
     *
     * This allows for reanalysis of files that have been opened
     * @param array<string, string> $files
     */
    public function queueFileAnalysisWithOpenedFiles(array $files = []): void
    {
        /** @var array<string, string> $opened */
        $opened = array_reduce(
            $this->project_analyzer->getCodebase()->file_provider->getOpenFilesPath(),
            function (array $opened, string $file_path) {
                $opened[$file_path] = $this->pathToUri($file_path);
                return $opened;
            },
            $files
        );

        $this->doVersionedAnalysis($opened);
    }

    /**
     * Debounced Queue File Analysis with optional version
     *
     * @param array<string, string> $files
     * @param int|null $version
     */
    public function doVersionedAnalysisDebounce(array $files, ?int $version = null): void
    {
        Loop::cancel($this->versionedAnalysisDelayToken);
        if ($this->client->clientConfiguration->onChangeDebounceMs === null) {
            $this->doVersionedAnalysis($files, $version);
        } else {
            $this->versionedAnalysisDelayToken = Loop::delay(
                $this->client->clientConfiguration->onChangeDebounceMs,
                fn() => $this->doVersionedAnalysis($files, $version)
            );
        }
    }

    /**
     * Queue File Analysis with optional version
     *
     * @param array<string, string> $files
     * @param int|null $version
     */
    public function doVersionedAnalysis(array $files, ?int $version = null): void
    {
        Loop::cancel($this->versionedAnalysisDelayToken);
        try {
            $this->logDebug("Doing Analysis from version: $version");
            $this->codebase->reloadFiles(
                $this->project_analyzer,
                array_keys($files)
            );

            $this->codebase->analyzer->addFilesToAnalyze(
                array_combine(array_keys($files), array_keys($files))
            );

            $this->logDebug("Reloading Files");
            $this->codebase->analyzer->analyzeFiles($this->project_analyzer, 1, false);

            $this->emitVersionedIssues($files, $version);
        } catch (Throwable $e) {
            $this->logError((string) $e);
        }
    }

    /**
     * Emit Publish Diagnostics
     *
     * @param array<string, string> $files
     * @param int|null $version
     */
    public function emitVersionedIssues(array $files, ?int $version = null): void
    {
        $this->logDebug("Perform Analysis", [
            'files' => array_keys($files),
            'version' => $version
        ]);

        $data = IssueBuffer::clear();
        foreach ($files as $file_path => $uri) {
            //Dont report errors in files we are not watching
            if (!$this->project_analyzer->getCodebase()->config->isInProjectDirs($file_path)) {
                continue;
            }
            $diagnostics = array_map(
                function (IssueData $issue_data): Diagnostic {
                    //$check_name = $issue->check_name;
                    $description = $issue_data->message;
                    $severity = $issue_data->severity;

                    $start_line = max($issue_data->line_from, 1);
                    $end_line = $issue_data->line_to;
                    $start_column = $issue_data->column_from;
                    $end_column = $issue_data->column_to;
                    // Language server has 0 based lines and columns, phan has 1-based lines and columns.
                    $range = new Range(
                        new Position($start_line - 1, $start_column - 1),
                        new Position($end_line - 1, $end_column - 1)
                    );
                    switch ($severity) {
                        case Config::REPORT_INFO:
                            $diagnostic_severity = DiagnosticSeverity::WARNING;
                            break;
                        case Config::REPORT_ERROR:
                        default:
                            $diagnostic_severity = DiagnosticSeverity::ERROR;
                            break;
                    }
                    $diagnostic = new Diagnostic(
                        $description,
                        $range,
                        null,
                        $diagnostic_severity,
                        'psalm'
                    );

                    $diagnostic->data = [
                        'type' => $issue_data->type,
                        'snippet' => $issue_data->snippet,
                        'line_from' => $issue_data->line_from,
                        'line_to' => $issue_data->line_to
                    ];

                    $diagnostic->code = $issue_data->shortcode;

                    /**
                     * Client supports a codeDescription property
                     *
                     * @since LSP 3.16.0
                     */
                    if ($this->clientCapabilities !== null &&
                        $this->clientCapabilities->textDocument &&
                        $this->clientCapabilities->textDocument->publishDiagnostics &&
                        $this->clientCapabilities->textDocument->publishDiagnostics->codeDescriptionSupport
                    ) {
                        $diagnostic->codeDescription = new CodeDescription($issue_data->link);
                    }

                    return $diagnostic;
                },
                array_filter(
                    $data[$file_path] ?? [],
                    function (IssueData $issue_data) {
                        //Hide Warnings
                        if ($issue_data->severity === Config::REPORT_INFO &&
                            $this->client->clientConfiguration->hideWarnings
                        ) {
                            return false;
                        }

                        return true;
                    }
                )
            );

            $this->client->textDocument->publishDiagnostics($uri, array_values($diagnostics), $version);
        }
    }

    /**
     * The shutdown request is sent from the client to the server. It asks the server to shut down, but to not exit
     * (otherwise the response might not be delivered correctly to the client). There is a separate exit notification
     * that asks the server to exit. Clients must not send any notifications other than exit or requests to a server to
     * which they have sent a shutdown request. Clients should also wait with sending the exit notification until they
     * have received a response from the shutdown request.
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function shutdown(): Promise
    {
        $this->clientStatus('closing');
        $this->logInfo("Shutting down...");
        $codebase = $this->project_analyzer->getCodebase();
        $scanned_files = $codebase->scanner->getScannedFiles();
        $codebase->file_reference_provider->updateReferenceCache(
            $codebase,
            $scanned_files
        );
        $this->clientStatus('closed');
        return new Success(null);
    }

    /**
     * A notification to ask the server to exit its process.
     * The server should exit with success code 0 if the shutdown request has been received before;
     * otherwise with error code 1.
     *
     */
    public function exit(): void
    {
        exit(0);
    }


    /**
     * Send log message to the client
     *
     * @psalm-param 1|2|3|4 $type
     * @param int $type The log type:
     *  - 1 = Error
     *  - 2 = Warning
     *  - 3 = Info
     *  - 4 = Log
     * @see MessageType
     * @param string  $message The log message to send to the client.
     * @param mixed[] $context The log context
     */
    public function log(int $type, string $message, array $context = []): void
    {
        $logLevel = $this->client->clientConfiguration->logLevel;
        if ($logLevel === null) {
            return;
        }

        if ($logLevel < $type) {
            return;
        }

        if (!empty($context)) {
            $message .= "\n" . json_encode($context, JSON_PRETTY_PRINT);
        }
        try {
            $this->client->logMessage(
                new LogMessage(
                    $type,
                    $message,
                )
            );
        } catch (Throwable $err) {
            // do nothing as we could potentially go into a loop here is not careful
            //TODO: Investigate if we can use error_log instead
        }
    }

    /**
     * Log Error message to the client
     *
     * @param string $message
     * @param array $context
     */
    public function logError(string $message, array $context = []): void
    {
        $this->log(MessageType::ERROR, $message, $context);
    }

    /**
     * Log Warning message to the client
     *
     * @param string $message
     * @param array $context
     */
    public function logWarning(string $message, array $context = []): void
    {
        $this->log(MessageType::WARNING, $message, $context);
    }

    /**
     * Log Info message to the client
     *
     * @param string $message
     * @param array $context
     */
    public function logInfo(string $message, array $context = []): void
    {
        $this->log(MessageType::INFO, $message, $context);
    }

    /**
     * Log Debug message to the client
     *
     * @param string $message
     * @param array $context
     */
    public function logDebug(string $message, array $context = []): void
    {
        $this->log(MessageType::LOG, $message, $context);
    }

    /**
     * Send status message to client. This is the same as sending a log message,
     * except this is meant for parsing by the client to present status updates in a UI.
     *
     * @param string $status The log message to send to the client. Should not contain colons `:`.
     * @param string|null $additional_info This is additional info that the client
     *                                       can use as part of the display message.
     */
    private function clientStatus(string $status, ?string $additional_info = null): void
    {
        try {
            $this->client->event(
                new LogMessage(
                    MessageType::INFO,
                    $status . (!empty($additional_info) ? ': ' . $additional_info : '')
                )
            );
        } catch (Throwable $err) {
            // do nothing
        }
    }

    /**
     * Transforms an absolute file path into a URI as used by the language server protocol.
     *
     * @param string $filepath
     * @psalm-pure
     */
    public static function pathToUri(string $filepath): string
    {
        $filepath = trim(str_replace('\\', '/', $filepath), '/');
        $parts = explode('/', $filepath);
        // Don't %-encode the colon after a Windows drive letter
        $first = array_shift($parts);
        if (substr($first, -1) !== ':') {
            $first = rawurlencode($first);
        }
        $parts = array_map('rawurlencode', $parts);
        array_unshift($parts, $first);
        $filepath = implode('/', $parts);

        return 'file:///' . $filepath;
    }

    /**
     * Transforms URI into file path
     *
     * @param string $uri
     */
    public static function uriToPath(string $uri): string
    {
        $fragments = parse_url($uri);
        if ($fragments === false
            || !isset($fragments['scheme'])
            || $fragments['scheme'] !== 'file'
            || !isset($fragments['path'])
        ) {
            throw new InvalidArgumentException("Not a valid file URI: $uri");
        }

        $filepath = urldecode($fragments['path']);

        if (strpos($filepath, ':') !== false) {
            if ($filepath[0] === '/') {
                $filepath = substr($filepath, 1);
            }
            $filepath = str_replace('/', '\\', $filepath);
        }

        $realpath = realpath($filepath);
        if ($realpath !== false) {
            return $realpath;
        }

        return $filepath;
    }
}
