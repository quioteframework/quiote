<?php
namespace Quiote\Validator;

use Quiote\Context;
use Quiote\Exception\ValidatorException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * BaseFileValidator is the base validator when validating files. 
 * It provides checking of the size and extension of a file for implementing 
 * validators.
 * Parameters:
 *   'min_size'     The minimum file size in byte, default 1
 *   'max_size'     The maximum file size in byte
 *   'extension'    list of valid extensions (delimited by ' ')
 *   'mime_type'    A regular expression checked against the MIME type of the
 *                  file as returned by the fileinfo extension. The mime type
 *                  string to match against is something like "application/pdf".
 *   'mime_type_include_charset' Whether the regex in parameter 'mime_type'
 *                               should be matched against a string containing
 *                               the charset info (as defined in RFC 2045), e.g.
 *                               "text/csv; charset=iso-8859-1".
 * Errors:
 *   'upload_failed' The upload of the file failed
 *   'min_size'      
 *   'max_size'      
 *   'extension'     The file doesn't have the required extension
 *   'mime_type'     The MIME type check failed
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class BaseFileValidator extends Validator
{
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), [
			'min_size', 'max_size', 'extension', 'mime_type', 'mime_type_include_charset',
		]);
	}

	/**
	 * @see        Validator::initialize
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [], array $arguments = [], array $errors = [])
	{
		if(!isset($parameters['source'])) {
			// Default to 'files' source (PSR-7 uploaded files) now that legacy data holders are removed
			$parameters['source'] = 'files';
		}

		parent::initialize($context, $parameters, $arguments, $errors);
		
		if($this->hasParameter('mime_type') && !extension_loaded('fileinfo')) {
			throw new ValidatorException('MIME type checks in file validators require the "fileinfo" PHP extension to be loaded.');
		}
	}

	/**
	 * Validates the input
	 * @return     bool The file is valid according to given parameters.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$hasUpload = false;
		$required = (bool) $this->getParameter('required', true);

		foreach($this->getArguments() as $argument) {
			$file = $this->getData($argument);

			if(!($file instanceof UploadedFileInterface)) {
				if($file === null) {
					continue;
				}
				$this->throwError('argument_wrong_type');
				return false;
			}

			if($file->getError() === UPLOAD_ERR_NO_FILE) {
				continue;
			}

			if($file->getError() !== UPLOAD_ERR_OK) {
				$this->throwError('upload_failed');
				return false;
			}

			$hasUpload = true;

			$size = (int)($file->getSize() ?? 0);
			if($size < $this->getParameter('min_size', 1)) {
				$this->throwError('min_size');
				return false;
			}
			if($this->hasParameter('max_size') && $size > $this->getParameter('max_size')) {
				$this->throwError('max_size');
				return false;
			}

			if($this->hasParameter('extension')) {
				$name = $file->getClientFilename() ?? '';
				$fileinfo = pathinfo($name) + ['extension' => ''];
				$extensions = $this->getParameter('extension', []);
				if(!is_array($extensions)) {
					$extensions = explode(' ', (string) $this->getParameter('extension'));
				}
                $extOk = array_any($extensions, fn($extension) => strtolower((string)$extension) === strtolower($fileinfo['extension']));
				if(!$extOk) {
					$this->throwError('extension');
					return false;
				}
			}

			if($this->hasParameter('mime_type')) {
				$includeCharset = $this->getParameter('mime_type_include_charset', false);
				$target = '';
				try {
					$stream = $file->getStream();
					$pos = $stream->tell();
					$buf = $stream->read(65535);
					$stream->seek($pos);
					$finfo = new \finfo($includeCharset ? FILEINFO_MIME : FILEINFO_MIME_TYPE);
					$target = $finfo->buffer($buf) ?: '';
				} catch (\Throwable) {
					$target = '';
				}
				if(!preg_match($this->getParameter('mime_type'), $target)) {
					$this->throwError('mime_type');
					return false;
				}
			}
		}

		if(!$hasUpload) {
			if($required) {
				$this->throwError('required');
				return false;
			}
			return true;
		}
		
		return true;
	}
}

?>