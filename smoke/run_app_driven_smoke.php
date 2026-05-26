<?php

declare(strict_types=1);

const PROJECT_TOKEN = 'dbundle_proj_smoke';
const SERVER_SERVICE = 'php-smoke-service';
const SERVER_ENVIRONMENT = 'smoke';
const SERVER_TRACE_ID = 'trace-smoke-server';
const SERVER_REQUEST_ID = 'req-smoke-server';
const RELAY_TRACE_ID = 'trace-smoke-relay';
const RELAY_REQUEST_ID = 'req-smoke-relay';
const BROWSER_SERVICE = 'checkout-web';
const BROWSER_ENVIRONMENT = 'production';

main($argv);

function main(array $argv): void
{
    $options = parseOptions($argv);
    $repoRoot = dirname(__DIR__);
    $composerCommand = composerCommand($repoRoot);
    $schemaPath = $repoRoot . '/tests/fixtures/event-envelope.schema.json';

    $projectDir = createTempDirectory('debugbundle-php-smoke-project-');
    $captureServer = null;

    try {
        writeSmokeProject($projectDir, $repoRoot, $options);
        runCommand(array_merge($composerCommand, ['install', '--no-interaction', '--prefer-dist', '--no-progress']), $projectDir);

        require $projectDir . '/vendor/autoload.php';

        $captureFile = tempnam(sys_get_temp_dir(), 'debugbundle-php-smoke-capture-');
        if ($captureFile === false) {
            throw new RuntimeException('Failed to allocate capture file for the smoke server.');
        }

        $captureServer = startCaptureServer($repoRoot, $captureFile);

        runServerEventSmoke($captureServer['endpoint'], (string) $options['version']);
        runRelaySmoke($captureServer['endpoint']);

        $requests = readCapturedRequests($captureFile);
        validateCapturedRequests($requests, $schemaPath, (string) $options['version']);

        echo "PHP app-driven smoke passed.\n";
    } finally {
        if (is_array($captureServer) && isset($captureServer['process']) && is_resource($captureServer['process'])) {
            proc_terminate($captureServer['process']);
            proc_close($captureServer['process']);
        }

        removeDirectory($projectDir);
    }
}

/**
 * @return array{mode:'artifact'|'package',artifact?:string,package?:string,version:string}
 */
function parseOptions(array $argv): array
{
    $artifactPath = null;
    $packageSpec = null;
    $version = null;

    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $argument = $argv[$index];
        switch ($argument) {
            case '--artifact':
                $artifactPath = $argv[++$index] ?? null;
                break;

            case '--package':
                $packageSpec = $argv[++$index] ?? null;
                break;

            case '--version':
                $version = $argv[++$index] ?? null;
                break;

            default:
                usage(sprintf('Unknown argument: %s', $argument));
        }
    }

    if (($artifactPath === null) === ($packageSpec === null)) {
        usage('Pass exactly one of --artifact <zip-path> or --package <name:version>.');
    }

    if ($artifactPath !== null) {
        if ($version === null || $version === '') {
            usage('Artifact mode requires --version <sdk-version>.');
        }

        $resolvedArtifactPath = realpath($artifactPath);
        if ($resolvedArtifactPath === false || !is_file($resolvedArtifactPath)) {
            throw new RuntimeException(sprintf('Missing smoke artifact: %s', (string) $artifactPath));
        }

        return [
            'mode' => 'artifact',
            'artifact' => $resolvedArtifactPath,
            'version' => $version,
        ];
    }

    if (!is_string($packageSpec) || $packageSpec === '') {
        usage('Package mode requires --package <name:version>.');
    }

    [$packageName, $packageVersion] = explode(':', $packageSpec, 2) + [null, null];
    if (!is_string($packageName) || $packageName === '' || !is_string($packageVersion) || $packageVersion === '') {
        usage('Package mode expects --package debugbundle/sdk-php:<version>.');
    }

    return [
        'mode' => 'package',
        'package' => $packageSpec,
        'version' => $packageVersion,
    ];
}

function usage(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Usage: php smoke/run_app_driven_smoke.php (--artifact <zip-path> --version <sdk-version> | --package <name:version>)' . PHP_EOL);
    exit(1);
}

/** @return list<string> */
function composerCommand(string $repoRoot): array
{
    $composerPhar = $repoRoot . '/composer.phar';
    if (is_file($composerPhar)) {
        return [PHP_BINARY, $composerPhar];
    }

    return ['composer'];
}

/**
 * @param array{mode:'artifact'|'package',artifact?:string,package?:string,version:string} $options
 */
function writeSmokeProject(string $projectDir, string $repoRoot, array $options): void
{
    $rootComposer = json_decode((string) file_get_contents($repoRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rootComposer)) {
        throw new RuntimeException('Failed to read PHP SDK composer metadata.');
    }

    $require = [
        'opis/json-schema' => '^2.6',
        'symfony/http-foundation' => '^7.2',
    ];

    $composerJson = [
        'name' => 'debugbundle/php-smoke-fixture',
        'minimum-stability' => 'stable',
        'prefer-stable' => true,
    ];

    if ($options['mode'] === 'artifact') {
        $packageName = (string) ($rootComposer['name'] ?? 'debugbundle/sdk-php');
        $packageVersion = (string) $options['version'];
        $packageArtifact = [
            'name' => $packageName,
            'version' => $packageVersion,
            'type' => (string) ($rootComposer['type'] ?? 'library'),
            'dist' => [
                'url' => 'file://' . (string) $options['artifact'],
                'type' => 'zip',
            ],
            'autoload' => $rootComposer['autoload'] ?? new stdClass(),
            'require' => $rootComposer['require'] ?? new stdClass(),
        ];

        $composerJson['repositories'] = [[
            'type' => 'package',
            'package' => $packageArtifact,
        ]];
        $require[$packageName] = $packageVersion;
    } else {
        $packageSpec = (string) $options['package'];
        [$packageName, $packageVersion] = explode(':', $packageSpec, 2);
        $require[$packageName] = $packageVersion;
    }

    $composerJson['require'] = $require;

    file_put_contents(
        $projectDir . '/composer.json',
        json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
    );
}

/**
 * @return array{endpoint:string,process:resource}
 */
function startCaptureServer(string $repoRoot, string $captureFile): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if ($socket === false) {
        throw new RuntimeException('Failed to allocate a TCP port for the smoke capture server.');
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) {
        throw new RuntimeException('Failed to resolve the smoke capture server address.');
    }

    $port = (int) substr((string) strrchr($address, ':'), 1);
    $routerPath = $repoRoot . '/smoke/ingest_capture_router.php';
    $stdoutPath = tempnam(sys_get_temp_dir(), 'debugbundle-php-smoke-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'debugbundle-php-smoke-err-');

    $process = proc_open(
        [PHP_BINARY, '-S', sprintf('127.0.0.1:%d', $port), $routerPath],
        [
            0 => ['pipe', 'r'],
            1 => ['file', (string) $stdoutPath, 'w'],
            2 => ['file', (string) $stderrPath, 'w'],
        ],
        $pipes,
        $repoRoot,
        array_merge($_ENV, ['DEBUGBUNDLE_CAPTURE_FILE' => $captureFile]),
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start the smoke capture server.');
    }

    fclose($pipes[0]);

    $deadline = microtime(true) + 5.0;
    do {
        $connection = @fsockopen('127.0.0.1', $port);
        if (is_resource($connection)) {
            fclose($connection);

            return [
                'endpoint' => sprintf('http://127.0.0.1:%d/v1/events', $port),
                'process' => $process,
            ];
        }

        usleep(100000);
    } while (microtime(true) < $deadline);

    proc_terminate($process);
    proc_close($process);

    throw new RuntimeException('Timed out waiting for the smoke capture server to start.');
}

function runServerEventSmoke(string $endpoint, string $expectedVersion): void
{
    $sdk = new \DebugBundle\DebugBundleSdk();
    $sdk->init([
        'projectToken' => PROJECT_TOKEN,
        'service' => SERVER_SERVICE,
        'environment' => SERVER_ENVIRONMENT,
        'endpoint' => $endpoint,
    ]);

    $request = [
        'method' => 'GET',
        'path' => '/smoke',
        'headers' => [
            'X-DebugBundle-Trace-Id' => SERVER_TRACE_ID,
            'X-Request-Id' => SERVER_REQUEST_ID,
            'X-Smoke-Header' => 'smoke-request',
        ],
        'query' => ['attempt' => '1'],
    ];

    $sdk->beginRequest($request);
    $sdk->captureMessage('php app-driven smoke message', 'error', ['feature' => 'app-driven-smoke']);
    $sdk->captureRequest(
        $request,
        [
            'status_code' => 503,
            'duration_ms' => 17,
            'headers' => ['content-type' => 'application/json'],
        ],
        ['feature' => 'app-driven-smoke']
    );
    $sdk->flush();
    $sdk->endRequest();

    if ($sdk->getStatus() !== 'healthy') {
        throw new RuntimeException(sprintf('Expected healthy SDK status after smoke flush, received %s.', $sdk->getStatus()));
    }

    if (!is_float($sdk->getLastEventAt())) {
        throw new RuntimeException('Expected getLastEventAt() to return a timestamp after a successful smoke flush.');
    }

    if ($expectedVersion === '') {
        throw new RuntimeException('Smoke runner did not receive an expected SDK version.');
    }
}

function runRelaySmoke(string $endpoint): void
{
    $controller = new \DebugBundle\Framework\Symfony\DebugBundleRelayController([
        'allowedOrigins' => ['https://app.example.com'],
        'projectMode' => 'connected',
        'projectToken' => PROJECT_TOKEN,
        'endpoint' => $endpoint,
        'durableWrite' => true,
    ]);

    $relayRequest = \Symfony\Component\HttpFoundation\Request::create(
        '/debugbundle/browser',
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://app.example.com',
            'REMOTE_ADDR' => '203.0.113.10',
        ],
        json_encode([
            'batch' => [[
                'schema_version' => '2026-03-01',
                'event_id' => '00000000-0000-4000-8000-000000000321',
                'event_type' => 'request_event',
                'occurred_at' => '2026-05-25T00:00:00Z',
                'sdk_name' => 'browser-should-be-overridden',
                'sdk_version' => '0.1.0',
                'project_token' => 'dbundle_proj_smuggled',
                'project_id' => '00000000-0000-4000-8000-000000000999',
                'service' => [
                    'name' => BROWSER_SERVICE,
                    'environment' => BROWSER_ENVIRONMENT,
                    'runtime' => null,
                    'framework' => null,
                ],
                'correlation' => [
                    'request_id' => RELAY_REQUEST_ID,
                    'trace_id' => RELAY_TRACE_ID,
                    'session_id' => 'sess-smoke',
                    'user_id_hash' => 'user-smoke',
                ],
                'payload' => [
                    'method' => 'GET',
                    'path' => '/relay-smoke',
                    'query' => ['view' => 'browser'],
                    'headers' => ['x-browser-header' => 'visible'],
                    'response_status' => 503,
                    'duration_ms' => 12,
                ],
            ]],
        ], JSON_THROW_ON_ERROR)
    );

    $response = $controller($relayRequest);
    if ($response->getStatusCode() !== 202) {
        throw new RuntimeException(sprintf('Expected the relay smoke route to return 202, received %d.', $response->getStatusCode()));
    }
}

/** @return list<array<string, mixed>> */
function readCapturedRequests(string $captureFile): array
{
    $contents = trim((string) file_get_contents($captureFile));
    if ($contents === '') {
        throw new RuntimeException('Expected the smoke flow to deliver at least one request to the mock ingestion endpoint.');
    }

    return array_map(
        static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => $line !== ''))
    );
}

/**
 * @param list<array<string, mixed>> $requests
 */
function validateCapturedRequests(array $requests, string $schemaPath, string $expectedVersion): void
{
    if (count($requests) !== 2) {
        throw new RuntimeException(sprintf('Expected two ingestion requests from the smoke flow, received %d.', count($requests)));
    }

    $serverRequest = null;
    $relayRequest = null;
    foreach ($requests as $request) {
        $events = $request['body']['events'] ?? null;
        if (!is_array($events) || $events === []) {
            continue;
        }

        $firstEvent = $events[0];
        $sdkName = is_array($firstEvent) ? ($firstEvent['sdk_name'] ?? null) : null;
        if ($sdkName === 'debugbundle/sdk-php') {
            $serverRequest = $request;
        }
        if ($sdkName === '@debugbundle/sdk-browser') {
            $relayRequest = $request;
        }
    }

    if (!is_array($serverRequest) || !is_array($relayRequest)) {
        throw new RuntimeException('Expected separate server-event and browser-relay ingest requests from the smoke flow.');
    }

    assertAuthorizationHeader($serverRequest, PROJECT_TOKEN);
    assertAuthorizationHeader($relayRequest, PROJECT_TOKEN);

    $schema = json_decode((string) file_get_contents($schemaPath));
    if ($schema === null) {
        throw new RuntimeException('Failed to decode the event envelope schema fixture for smoke validation.');
    }

    $validator = new \Opis\JsonSchema\Validator();
    $formatter = new \Opis\JsonSchema\Errors\ErrorFormatter();

    $serverEvents = $serverRequest['body']['events'];
    if (count($serverEvents) < 2) {
        throw new RuntimeException('Expected the server smoke request to include both a log_event and a request_event.');
    }

    $serverEventTypes = array_map(static fn (array $event): string => (string) ($event['event_type'] ?? ''), $serverEvents);
    if (!in_array('log_event', $serverEventTypes, true) || !in_array('request_event', $serverEventTypes, true)) {
        throw new RuntimeException('Expected the server smoke request to include log_event and request_event payloads.');
    }

    foreach ($serverEvents as $event) {
        assertSchemaValid($validator, $formatter, $schema, $event);
        if (($event['sdk_name'] ?? null) !== 'debugbundle/sdk-php') {
            throw new RuntimeException('Expected server events to keep the PHP SDK name.');
        }
        if (($event['sdk_version'] ?? null) !== $expectedVersion) {
            throw new RuntimeException(sprintf('Expected server smoke events to emit sdk_version %s, received %s.', $expectedVersion, (string) ($event['sdk_version'] ?? '')));
        }
        if (($event['service']['name'] ?? null) !== SERVER_SERVICE || ($event['service']['environment'] ?? null) !== SERVER_ENVIRONMENT) {
            throw new RuntimeException('Expected server smoke events to preserve the configured service and environment.');
        }
    }

    $smokeLogEvent = findEventByType($serverEvents, 'log_event');
    if (($smokeLogEvent['payload']['message'] ?? null) !== 'php app-driven smoke message') {
        throw new RuntimeException('Expected the server smoke log_event message to match the app-driven smoke payload.');
    }
    if (($smokeLogEvent['correlation']['trace_id'] ?? null) !== SERVER_TRACE_ID || ($smokeLogEvent['correlation']['request_id'] ?? null) !== SERVER_REQUEST_ID) {
        throw new RuntimeException('Expected the server smoke log_event to carry request correlation fields.');
    }

    $smokeRequestEvent = findEventByType($serverEvents, 'request_event');
    if (($smokeRequestEvent['payload']['path'] ?? null) !== '/smoke' || ($smokeRequestEvent['payload']['response_status'] ?? null) !== 503) {
        throw new RuntimeException('Expected the server smoke request_event to capture the smoke route path and 503 response.');
    }

    $relayEvents = $relayRequest['body']['events'];
    if (count($relayEvents) !== 1) {
        throw new RuntimeException(sprintf('Expected one forwarded relay event, received %d.', count($relayEvents)));
    }

    $relayEvent = $relayEvents[0];
    assertSchemaValid($validator, $formatter, $schema, $relayEvent);
    if (($relayEvent['sdk_name'] ?? null) !== '@debugbundle/sdk-browser') {
        throw new RuntimeException('Expected the relay smoke event to force the browser SDK name.');
    }
    if (($relayEvent['service']['name'] ?? null) !== BROWSER_SERVICE || ($relayEvent['service']['environment'] ?? null) !== BROWSER_ENVIRONMENT) {
        throw new RuntimeException('Expected the relay smoke event to preserve browser-owned service identity.');
    }
    if (($relayEvent['correlation']['trace_id'] ?? null) !== RELAY_TRACE_ID || ($relayEvent['correlation']['request_id'] ?? null) !== RELAY_REQUEST_ID) {
        throw new RuntimeException('Expected the relay smoke event to preserve browser-owned correlation fields.');
    }
    if (array_key_exists('project_token', $relayEvent) || array_key_exists('project_id', $relayEvent)) {
        throw new RuntimeException('Expected relay forwarding to strip browser-supplied trust fields before transport.');
    }
}

function assertSchemaValid(\Opis\JsonSchema\Validator $validator, \Opis\JsonSchema\Errors\ErrorFormatter $formatter, object $schema, array $event): void
{
    $result = $validator->validate(json_decode((string) json_encode($event, JSON_THROW_ON_ERROR)), $schema);
    if ($result->isValid()) {
        return;
    }

    $error = $result->error();
    $formatted = $error !== null
        ? json_encode($formatter->format($error), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        : 'schema validation failed without error details';

    throw new RuntimeException('Smoke event schema validation failed: ' . $formatted);
}

/** @param array<string, mixed> $request */
function assertAuthorizationHeader(array $request, string $expectedProjectToken): void
{
    $headers = $request['headers'] ?? null;
    if (!is_array($headers)) {
        throw new RuntimeException('Expected the smoke capture request to include response headers for validation.');
    }

    $authorization = $headers['Authorization'] ?? null;
    $expected = 'Bearer ' . $expectedProjectToken;
    if ($authorization !== $expected) {
        throw new RuntimeException(sprintf('Expected Authorization header %s, received %s.', $expected, is_string($authorization) ? $authorization : 'missing'));
    }
}

/**
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function findEventByType(array $events, string $eventType): array
{
    foreach ($events as $event) {
        if (($event['event_type'] ?? null) === $eventType) {
            return $event;
        }
    }

    throw new RuntimeException(sprintf('Missing expected smoke event type: %s.', $eventType));
}

/** @param list<string> $command */
function runCommand(array $command, ?string $cwd = null): void
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $cwd,
        $_ENV,
    );

    if (!is_resource($process)) {
        throw new RuntimeException(sprintf('Failed to start command: %s', implode(' ', $command)));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode === 0) {
        return;
    }

    throw new RuntimeException(sprintf(
        "Command failed with exit code %d: %s\n%s%s",
        $exitCode,
        implode(' ', $command),
        $stdout !== false && $stdout !== '' ? $stdout . "\n" : '',
        $stderr !== false ? $stderr : ''
    ));
}

function createTempDirectory(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false) {
        throw new RuntimeException('Failed to allocate a temporary path for the smoke project.');
    }

    unlink($path);
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException(sprintf('Failed to create smoke directory %s.', $path));
    }

    return $path;
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        if (is_file($directory)) {
            unlink($directory);
        }

        return;
    }

    $entries = scandir($directory);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            removeDirectory($path);
            continue;
        }

        unlink($path);
    }

    rmdir($directory);
}