<?php

namespace Quiote\Execution;

use Quiote\Context;
use Quiote\Controller\ExecutionContainer;
use Quiote\Response\WebResponse;
use Quiote\Util\AttributeHolder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Adapts the legacy ExecutionContainer to the ActionInitContext/ViewInitContext
 * contracts, since the container's own getters are untyped and cannot
 * implement those interfaces directly (e.g. getRequestData() may legitimately
 * return a plain array, not just a PSR-7 request).
 *
 * One instance is shared between an action's and its view's initialize() call
 * (see ExecutionContainer::getActionInstance()/getViewInstance()) so attributes
 * set on the action's context remain visible to the view, matching the
 * behaviour of the container-less pipeline's Lightweight/Immutable contexts.
 */
final class LegacyContainerInitContext extends AttributeHolder implements ActionInitContext, ViewInitContext
{
    public function __construct(private readonly ExecutionContainer $container)
    {
    }

    public function getContext(): Context
    {
        return $this->container->getContext();
    }

    public function getModuleName(): string
    {
        return $this->container->getModuleName();
    }

    public function getActionName(): string
    {
        return $this->container->getActionName();
    }

    public function getActionModuleName(): string
    {
        return $this->container->getModuleName();
    }

    public function getRequestMethod(): string
    {
        return $this->container->getRequestMethod();
    }

    public function getOutputTypeName(): string
    {
        return $this->container->getOutputType()->getName();
    }

    public function getRequestData(): ?ServerRequestInterface
    {
        $requestData = $this->container->getRequestData();
        return $requestData instanceof ServerRequestInterface ? $requestData : null;
    }

    public function getResponse(): WebResponse
    {
        $response = $this->container->getResponse();
        if ($response === null) {
            throw new \LogicException('ExecutionContainer has no response instance.');
        }
        return $response;
    }

    public function getPsrResponse(): ?ResponseInterface
    {
        return $this->container->getResponse()?->getPsrResponse();
    }

    public function setViewModuleName(?string $module): void
    {
        $this->container->setViewModuleName($module);
    }

    public function setViewName(?string $name): void
    {
        $this->container->setViewName($name);
    }

    public function getViewModuleName(): string
    {
        return $this->container->getViewModuleName();
    }

    public function getViewName(): string
    {
        return $this->container->getViewName();
    }

    /**
     * @return array<string, mixed>
     */
    public function getActionAttributes(): array
    {
        // Attribute names are always strings by contract; re-key defensively so a stray
        // int-keyed entry from AttributeHolder internals can never desync consumers
        // that index this snapshot by name.
        $attributes = $this->getAttributes();
        return array_combine(
            array_map('strval', array_keys($attributes)),
            array_values($attributes)
        );
    }

    /**
     * @return mixed
     */
    public function getValidationManager()
    {
        return $this->container->getValidationManager();
    }
}
