<?php

namespace Agavi\Execution;

use Agavi\AgaviContext;
use Agavi\Response\AgaviResponse;
use Agavi\Response\AgaviWebResponse;
use Agavi\Validator\AgaviValidationManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface ActionInitContext
{
    public function getContext(): AgaviContext;
    public function getModuleName(): string;
    public function getActionName(): string;
    public function getRequestMethod(): string;
    public function getOutputTypeName(): string;
    public function getRequestData(): ?ServerRequestInterface;
    public function getResponse(): AgaviWebResponse;
    // Attribute methods inherited from AgaviAttributeHolder via LightweightActionInitContext extension; intentionally not part of strict interface to avoid signature conflicts.
    public function setViewModuleName(?string $module): void;
    public function setViewName(?string $name): void;
    public function getViewModuleName(): ?string;
    public function getViewName(): ?string;
    public function getValidationManager();
    
}
