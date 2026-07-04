<?php

declare(strict_types=1);

namespace Dejavu\Benchmark;

/**
 * Adapter that grades the *real* dejavu push hook instead of the reference engine.
 *
 * The benchmark stays store-agnostic: rather than assume a Postgres factStore, the
 * adapter talks to a thin shim over dejavu-push.php through a simple line protocol.
 * Point the DEJAVU_PUSH_CMD env var at an executable that:
 *
 *   • reads ONE json object on stdin:
 *       {"prompt": "...", "context": {...}, "seed": [ {fact}, ... ], "reset": true|false,
 *        "session": "s1"|null}
 *     `reset` is true on the first turn of each case (load the seed into a scratch
 *     store / clear session state), false on subsequent turns of the same case.
 *     `session` is the turn's session id (multi-session cases). It stays the same
 *     across turns of one session and changes at a session boundary; the shim
 *     should reset *session-scoped* state (habituation) when it changes while
 *     keeping long-term facts. `null` means one implicit session for the case.
 *   • writes ONE json object on stdout:
 *       {"pushed": ["slug-a", "slug-b"]}   // slugs delivered, in order
 *
 * The shim is where the store-specific wiring lives (seed the DB, invoke the hook,
 * parse the additionalContext it emits back to slugs). Keeping it external means
 * this repo runs standalone on the reference engine and only reaches for a live
 * store when you ask it to.
 */
final class DejavuPushEngine implements EngineInterface
{
    private string $cmd;
    private array $seed = [];
    private bool $firstTurn = true;

    public function __construct(?string $cmd = null)
    {
        $cmd = $cmd ?? getenv('DEJAVU_PUSH_CMD') ?: '';
        if ($cmd === '') {
            throw new \RuntimeException(
                "DejavuPushEngine needs a shim: set DEJAVU_PUSH_CMD to an executable "
                . "implementing the stdin/stdout json protocol (see runner/lib/DejavuPushEngine.php)."
            );
        }
        $this->cmd = $cmd;
    }

    public function name(): string
    {
        return 'dejavu-push';
    }

    public function loadCase(array $case): void
    {
        $this->seed = $case['seed'] ?? [];
        $this->firstTurn = true;
    }

    public function push(array $turn): array
    {
        $payload = [
            'prompt' => $turn['prompt'] ?? '',
            'context' => $turn['context'] ?? new \stdClass(),
            'seed' => $this->seed,
            'reset' => $this->firstTurn,
            'session' => $turn['session'] ?? $turn['session_id'] ?? null,
        ];
        $this->firstTurn = false;

        $out = $this->invoke(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $decoded = json_decode($out, true);
        if (!is_array($decoded) || !isset($decoded['pushed']) || !is_array($decoded['pushed'])) {
            throw new \RuntimeException("Shim returned malformed json: " . substr($out, 0, 200));
        }
        return array_values(array_map('strval', $decoded['pushed']));
    }

    private function invoke(string $stdin): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($this->cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException("Could not start DEJAVU_PUSH_CMD: {$this->cmd}");
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new \RuntimeException("Shim exited {$code}: " . trim($stderr));
        }
        return (string)$stdout;
    }
}
