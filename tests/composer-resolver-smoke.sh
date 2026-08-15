#!/usr/bin/env sh
set -eu

smoke_root=$(mktemp -d "${TMPDIR:-/tmp}/durable-workflow-ai-resolver.XXXXXX")

cleanup() {
    rm -rf -- "$smoke_root"
}
trap cleanup EXIT HUP INT TERM

composer init \
    --working-dir="$smoke_root" \
    --name=durable-workflow/ai-source-smoke \
    --description='Durable Workflow AI public source resolver smoke' \
    --type=project \
    --no-interaction \
    >/dev/null

composer require \
    --working-dir="$smoke_root" \
    --no-install \
    --no-interaction \
    --no-progress \
    --no-audit \
    'durable-workflow/workflow:^2.0@RC' \
    'durable-workflow/ai:dev-main'

php -r '
function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

$root = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);

if (array_key_exists("minimum-stability", $root)) {
    fail("The smoke root must retain Composer default stability.");
}

if (array_key_exists("repositories", $root)) {
    fail("The smoke root must resolve through the default public Composer repository.");
}

$packages = [];
foreach ($lock["packages"] ?? [] as $package) {
    $packages[$package["name"]] = $package;
}

$ai = $packages["durable-workflow/ai"] ?? null;
$workflow = $packages["durable-workflow/workflow"] ?? null;

if (($ai["version"] ?? null) !== "dev-main") {
    fail("Composer did not resolve durable-workflow/ai dev-main.");
}

$source = $ai["source"] ?? [];
$sourceReference = $source["reference"] ?? "";

if (($source["type"] ?? null) !== "git"
    || ($source["url"] ?? null) !== "https://github.com/durable-workflow/ai.git"
    || preg_match("/^[0-9a-f]{40}$/D", $sourceReference) !== 1) {
    fail("Composer did not resolve durable-workflow/ai from its public Git source.");
}

if ($workflow === null) {
    fail("Composer did not resolve the Durable Workflow runtime.");
}

fwrite(
    STDOUT,
    sprintf(
        "Resolved durable-workflow/ai %s at public source %s with durable-workflow/workflow %s.%s",
        $ai["version"],
        $sourceReference,
        $workflow["version"],
        PHP_EOL,
    ),
);
' "$smoke_root/composer.json" "$smoke_root/composer.lock"
