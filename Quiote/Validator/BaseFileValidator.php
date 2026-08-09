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
	/**
	 * Returns the base Validator parameters plus 'min_size', 'max_size',
	 * 'extension', 'mime_type' and 'mime_type_include_charset', shared by every
	 * file validator.
	 *
	 * 'min_size' and 'max_size' bound the uploaded file's size in bytes, the
	 * minimum defaulting to 1 so a zero-byte upload fails. 'extension' is a list
	 * of acceptable filename extensions, given either as an array or as a
	 * space-delimited string, matched case-insensitively against the client
	 * filename. 'mime_type' is a PCRE matched against the type fileinfo detects
	 * from the file's own content, and requires the fileinfo extension to be
	 * loaded; 'mime_type_include_charset' makes that match run against the type
	 * with the charset appended ("text/csv; charset=iso-8859-1") rather than the
	 * bare type. Subclasses merge their own names onto this set.
	 * @return     array<int, string> The accepted parameter names.
	 */
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
				$extensionsRaw = $this->getParameter('extension', []);
				if(is_array($extensionsRaw)) {
					$extensions = $extensionsRaw;
				} else {
					if(!is_string($extensionsRaw)) {
						throw $this->invalidParameterType('extension', 'a string or an array', $extensionsRaw);
					}
					$extensions = explode(' ', $extensionsRaw);
				}
				$extOk = array_any($extensions, function($extension) use ($fileinfo) {
					if(!is_string($extension)) {
						throw $this->invalidParameterType('extension', 'a list of strings', $extension);
					}
					return strtolower($extension) === strtolower($fileinfo['extension']);
				});
				if(!$extOk) {
					$this->throwError('extension');
					return false;
				}
			}

			if($this->hasParameter('mime_type')) {
				$mimeTypePattern = $this->getParameter('mime_type');
				if(!is_string($mimeTypePattern)) {
					throw $this->invalidParameterType('mime_type', 'a string', $mimeTypePattern);
				}
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
				if(!preg_match($mimeTypePattern, $target)) {
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