<?php

namespace Quiote\Testing;

/**
 * Trait for adding PHPUnit 12 compatibility to PhpUnitTestCase */
trait PHPUnitTestCaseMethods
{
    /**
     * Get test annotations/attributes in a format compatible with both PHPUnit < 10 and >= 10
     * @return array The annotations
     */
    protected function getAnnotations(): array
    {
        $annotations = [
            'class' => [],
            'method' => []
        ];
        
        $reflector = new \ReflectionClass($this);
        
        // Handle PHP 8 class attributes
        foreach ($reflector->getAttributes() as $attribute) {
            $attrName = $attribute->getName();
            $shortName = substr($attrName, strrpos($attrName, '\\') + 1);
            
            // Convert attribute names to match the old annotation format
            if (!isset($annotations['class'][$shortName])) {
                $annotations['class'][$shortName] = [];
            }
            
            // Handle attributes with or without arguments
            $instance = $attribute->newInstance();
            if (method_exists($instance, 'getValue')) {
                $annotations['class'][$shortName][] = $instance->getValue();
            } elseif (property_exists($instance, 'value')) {
                $annotations['class'][$shortName][] = $instance->value;
            } else {
                $annotations['class'][$shortName][] = true;
            }
            
            // Special handling for Quiote custom annotations
            if (str_starts_with($shortName, 'quiote')) {
                $quioteName = lcfirst(substr($shortName, 5)); 
                $annotations['class'][$quioteName] = $annotations['class'][$shortName];
            }
        }
        
        // Get the current test method
        $methodName = $this->name();
        if ($methodName) {
            try {
                $method = $reflector->getMethod($methodName);
                
                // Handle PHP 8 method attributes
                foreach ($method->getAttributes() as $attribute) {
                    $attrName = $attribute->getName();
                    $shortName = substr($attrName, strrpos($attrName, '\\') + 1);
                    
                    if (!isset($annotations['method'][$shortName])) {
                        $annotations['method'][$shortName] = [];
                    }
                    
                    // Handle attributes with or without arguments
                    $instance = $attribute->newInstance();
                    if (method_exists($instance, 'getValue')) {
                        $annotations['method'][$shortName][] = $instance->getValue();
                    } elseif (property_exists($instance, 'value')) {
                        $annotations['method'][$shortName][] = $instance->value;
                    } else {
                        $annotations['method'][$shortName][] = true;
                    }
                    
                    // Special handling for Quiote custom annotations
                    if (str_starts_with($shortName, 'quiote')) {
                        $quioteName = lcfirst(substr($shortName, 5)); 
                        $annotations['method'][$quioteName] = $annotations['method'][$shortName];
                    }
                }
            } catch (\ReflectionException) {
                // Method not found, ignore
            }
        }
        
        // Parse docblock annotations if present (for backward compatibility)
        $this->parseDocBlockAnnotations($reflector, $annotations['class']);
        
        if ($methodName) {
            try {
                $method = $reflector->getMethod($methodName);
                $this->parseDocBlockAnnotations($method, $annotations['method']);
            } catch (\ReflectionException) {
                // Method not found, ignore
            }
        }
        
        return $annotations;
    }
    
    /**
     * Parse docblock annotations
     * @param \ReflectionClass|\ReflectionMethod $reflection Reflection object
     * @param array &$target Target array for annotations
     */
    private function parseDocBlockAnnotations($reflection, array &$target): void
    {
        $docComment = $reflection->getDocComment();
        if (!$docComment) {
            return;
        }
        
        // Parse Quiote-specific annotations
        $quiotePattern = '/@quiote([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s+(.+?)(?=\s*@|\s*\*\/|\s*$)/s';
        if (preg_match_all($quiotePattern, (string) $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $annotationName = lcfirst($match[1]); // Convert IsolationEnvironment -> isolationEnvironment
                $annotationValue = trim($match[2]);
                
                if (!isset($target[$annotationName])) {
                    $target[$annotationName] = [];
                }
                
                $target[$annotationName][] = $annotationValue;
                
                // Also add with the quiote prefix for backward compatibility
                $fullName = 'quiote' . $match[1];
                if (!isset($target[$fullName])) {
                    $target[$fullName] = [];
                }
                $target[$fullName][] = $annotationValue;
            }
        }
        
        // Parse general PHPUnit annotations
        $pattern = '/@([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s+(.+?)(?=\s*@|\s*\*\/|\s*$)/s';
        if (preg_match_all($pattern, (string) $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $annotationName = $match[1];
                $annotationValue = trim($match[2]);
                
                if (!isset($target[$annotationName])) {
                    $target[$annotationName] = [];
                }
                
                $target[$annotationName][] = $annotationValue;
            }
        }
    }
    
    /**
     * Create attributes for custom annotations
     * @param string $name The name of the annotation
     * @param mixed $value The value of the annotation
     * @return void
     */
    public function createAttribute(string $name, $value = null): void
    {
        // This is a placeholder for future attribute support
    }
}
