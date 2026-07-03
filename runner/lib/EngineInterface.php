<?php

declare(strict_types=1);

namespace Dejavu\Benchmark;

/**
 * A push-memory engine under test.
 *
 * The runner drives one engine per benchmark case. A case is a *session*: the
 * engine loads the case's seed facts once, then receives the turns in order.
 * State that spans turns (habituation, STM eviction) lives inside the engine.
 */
interface EngineInterface
{
    /**
     * Human-readable engine id, recorded in the result document.
     */
    public function name(): string;

    /**
     * Start a fresh session for one case: install its seed facts and reset any
     * per-session state (habituation, surfaced set, ...).
     *
     * @param array $case Decoded case (see cases/README.md).
     */
    public function loadCase(array $case): void;

    /**
     * Process one turn and return the slugs actually delivered ("pushed"),
     * in delivery order. An empty array means the channel stayed silent.
     *
     * @param array $turn Decoded turn (prompt + optional context).
     * @return string[]
     */
    public function push(array $turn): array;
}
