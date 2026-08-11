<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionContext;
use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateContext;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\Locking\LockState;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
use Throwable;

/**
 * Centralizes event creation and dispatching for StateWorker
 *
 * Orchestrator pattern requires knowledge of all event types (26 dependencies, threshold 13)
 * Orchestrator pattern requires one public method per event type (15 methods, threshold 10)
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class EventOrchestrator
{
    public function __construct(
        private readonly EventDispatcher $dispatcher
    ) {}

    public function gateEvaluating(Gate $gate, GateContext $context, bool $isActionGate): void
    {
        $this->dispatcher->dispatch(new GateEvaluating($gate, $context, $isActionGate));
    }

    public function gateEvaluated(Gate $gate, GateContext $context, GateResult $result, bool $isActionGate): void
    {
        $this->dispatcher->dispatch(new GateEvaluated($gate, $context, $result, $isActionGate));
    }

    public function actionExecuting(Action $action, ActionContext $context): void
    {
        $this->dispatcher->dispatch(new ActionExecuting($action, $context));
    }

    public function actionExecuted(Action $action, ActionContext $context, ActionResult $result): void
    {
        $this->dispatcher->dispatch(new ActionExecuted($action, $context, $result));
    }

    public function actionSkipped(Action $action, GateResult $reason): void
    {
        $this->dispatcher->dispatch(new ActionSkipped($action, $reason));
    }

    public function transitionCompleted(State $state, TransitionContext $context): void
    {
        $this->dispatcher->dispatch(new TransitionCompleted($state, $context));
    }

    public function transitionFailed(State $state, Throwable $exception, TransitionContext $context): void
    {
        $this->dispatcher->dispatch(new TransitionFailed($state, $exception, $context));
    }

    public function transitionPaused(State $state, TransitionContext $context, mixed $metadata): void
    {
        $this->dispatcher->dispatch(new TransitionPaused($state, $context, $metadata));
    }

    public function transitionStopped(State $state, TransitionContext $context, mixed $metadata): void
    {
        $this->dispatcher->dispatch(new TransitionStopped($state, $context, $metadata));
    }

    public function transitionYielded(State $state, TransitionContext $context, mixed $metadata): void
    {
        $this->dispatcher->dispatch(new TransitionYielded($state, $context, $metadata));
    }

    public function lockAcquired(string $lockKey, LockState $lockState): void
    {
        $this->dispatcher->dispatch(new LockAcquired($lockKey, $lockState));
    }

    public function lockFailed(string $lockKey, State $state, string $reason): void
    {
        $this->dispatcher->dispatch(new LockFailed($lockKey, $state, $reason));
    }

    public function lockReleased(string $lockKey, State $state): void
    {
        $this->dispatcher->dispatch(new LockReleased($lockKey, $state));
    }

    public function lockRestored(string $lockKey, State $state): void
    {
        $this->dispatcher->dispatch(new LockRestored($lockKey, $state));
    }

    public function lockLost(string $lockKey, State $state): void
    {
        $this->dispatcher->dispatch(new LockLost($lockKey, $state));
    }
}
