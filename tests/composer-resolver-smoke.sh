#!/usr/bin/env sh
set -eu

package_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
smoke_root=$(mktemp -d "${TMPDIR:-/tmp}/durable-workflow-ai-resolver.XXXXXX")

cleanup() {
    rm -rf -- "$smoke_root"
}
trap cleanup EXIT HUP INT TERM

composer init \
    --working-dir="$smoke_root" \
    --name=durable-workflow/ai-prerelease-smoke \
    --description='Durable Workflow AI prerelease resolver smoke' \
    --type=project \
    --no-interaction \
    >/dev/null

repository_json=$(php -r '
echo json_encode([
    "type" => "path",
    "url" => $argv[1],
    "options" => [
        "symlink" => false,
        "versions" => ["durable-workflow/ai" => "2.0.0-rc.7"],
    ],
], JSON_THROW_ON_ERROR);
' "$package_root")

composer config \
    --working-dir="$smoke_root" \
    repositories.ai \
    "$repository_json"

composer require \
    --working-dir="$smoke_root" \
    --no-install \
    --no-interaction \
    --no-progress \
    --no-audit \
    'durable-workflow/workflow:^2.0@RC' \
    'durable-workflow/ai:2.0.0-rc.7@RC'

php -r '
function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

$root = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$packageRoot = realpath($argv[3]);

if (array_key_exists("minimum-stability", $root)) {
    fail("The smoke root must retain Composer default stability.");
}

$packages = [];
foreach ($lock["packages"] ?? [] as $package) {
    $packages[$package["name"]] = $package;
}

$ai = $packages["durable-workflow/ai"] ?? null;
$workflow = $packages["durable-workflow/workflow"] ?? null;

if (($ai["version"] ?? null) !== "2.0.0-rc.7") {
    fail("Composer did not resolve durable-workflow/ai 2.0.0-rc.7.");
}

if (($ai["dist"]["type"] ?? null) !== "path"
    || realpath($ai["dist"]["url"] ?? "") !== $packageRoot) {
    fail("Composer did not resolve durable-workflow/ai from the checkout.");
}

if ($workflow === null) {
    fail("Composer did not resolve the Durable Workflow runtime.");
}

fwrite(
    STDOUT,
    sprintf(
        "Resolved durable-workflow/ai %s from the checkout with durable-workflow/workflow %s.%s",
        $ai["version"],
        $workflow["version"],
        PHP_EOL,
    ),
);
' "$smoke_root/composer.json" "$smoke_root/composer.lock" "$package_root"
