<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Model\Model;

class SampleModel extends Model {}

class ModelTest extends UnitTestCase
{
	public function testGetContext(): void
	{
		$context = $this->getContext();
		$model = new SampleModel();
		$model->initialize($context);
		$mContext = $model->getContext();
		$this->assertSame($mContext, $context);
	}

}
?>