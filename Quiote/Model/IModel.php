<?php
namespace Quiote\Model;
/**
 * Model provides a convention for separating business logic from 
 * application logic. When using a model you're providing a globally accessible
 * API for other modules to access, which will boost interoperability among 
 * modules in your web application.
 * @since      1.0.0
 * @version    1.0.0
 */
interface IModel
{
	public function getContext();
}

?>