<?php
namespace Quiote\Validator;

/**
 * ImageFileValidator verifies a parameter is an uploaded image
 * Parameters:
 *   'min_width'    The minimum width of the image
 *   'max_width'    The maximum width of the image
 *   'min_height'   The minimum height of the image
 *   'max_height'   The maximum height of the image
 *   'format'       list of valid formats (gif,jpeg,png,bmp,psd,swf)
 * Errors:
 *   'no_image'      The uploaded file is no image
 *   'min_width'
 *   'max_width'
 *   'min_height'
 *   'max_height'
 *   'format'        The image was not in the required format
 * @see        BaseFileValidator
 * @since      1.0.0
 * @version    1.0.0
 */
class ImageFileValidator  extends BaseFileValidator
{
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), [
			'min_width', 'max_width', 'min_height', 'max_height', 'format',
		]);
	}

	/**
	 * Validates the input.
	 * @return     bool File is valid image according to given parameters.
	 * @since      1.0.0
	 */
	#[\Override]
    protected function validate()
	{
		if(!parent::validate()) {
			return false;
		}

		$file = $this->getData($this->getArgument());

		$type = @getimagesize($file->getTmpName());
		if($type === false) {
			$this->throwError('no_image');
			return false;
		}

		[$width, $height, $imageType] = $type;

		if($this->hasParameter('max_width') && $width > $this->getParameter('max_width')) {
			$this->throwError('max_width');
			return false;
		}
		if($this->hasParameter('min_width') && $width < $this->getParameter('min_width')) {
			$this->throwError('min_width');
			return false;
		}

		if($this->hasParameter('max_height') && $height > $this->getParameter('max_height')) {
			$this->throwError('max_height');
			return false;
		}
		if($this->hasParameter('min_height') && $height < $this->getParameter('min_height')) {
			$this->throwError('min_height');
			return false;
		}

		if(!$this->hasParameter('format')) {
			return true;
		}
		
		// We need this additional alias map because image_type_to_extension()
		// returns only "jpeg" but not "jpg" or "tiff" but not "tif"
		$aliases = [
			IMAGETYPE_JPEG    => 'jpg',
			IMAGETYPE_TIFF_II => 'tif',
			IMAGETYPE_TIFF_MM => 'tif',
		];
		$ext = image_type_to_extension($imageType, false);
		
		$format = $this->getParameter('format', []);
		
		if(!is_array($format)) {
			$format = explode(' ', (string) $this->getParameter('format'));
		}
		
		foreach($format as $name) {
			$lName = strtolower((string) $name);
			if($ext == $lName) {
				return true;
			} elseif(isset($aliases[$imageType]) && $aliases[$imageType] == $name) {
				return true;
			}
		}
		
		$this->throwError('format');
		return false;
	}
}

?>