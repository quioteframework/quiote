<?php

// A template: statements at the top level of a file, no class anywhere in it. Both the rewriting rules
// and this reporter hang off Class_, so nothing here was ever seen -- the site rendered as a call to a
// deleted method at run time and the report said the file was clean.
$locale = $this->getContext()->getTranslationManager()->getCurrentLocale();
$service = $this->getContext()->getService(\Quiote\Routing\Routing::class);

echo $locale . $service;
