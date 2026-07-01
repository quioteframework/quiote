<?php

namespace Quiote\Execution;

use Quiote\Context;
use Quiote\Response\WebResponse;
use Quiote\Validator\ValidationManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface ActionInitContext
{
    public function getContext(): Context;
    public function getModuleName(): string;
    public function getActionName(): string;
    public function getRequestMethod(): string;
    public function getOutputTypeName(): string;
    public function getRequestData(): ?ServerRequestInterface;
    public function getResponse(): WebResponse;
    // Attribute methods inherited from AttributeHolder via LightweightActionInitContext extension; intentionally not part of strict interface to avoid signature conflicts.
    public function setViewModuleName(?string $module): void;
    public function setViewName(?string $name): void;
    public function getViewModuleName(): ?string;
    public function getViewName(): ?string;
    public function getValidationManager();
    
}
