<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\Validator;

use Agavi\AgaviContext;
use Agavi\Exception\AgaviValidatorException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * AgaviBaseFileValidator is the base validator when validating files. 
 * It provides checking of the size and extension of a file for implementing 
 * validators.
 * 
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
 *
 * Errors:
 *   'upload_failed' The upload of the file failed
 *   'min_size'      
 *   'max_size'      
 *   'extension'     The file doesn't have the required extension
 *   'mime_type'     The MIME type check failed
 *
 * @package    agavi
 * @subpackage validator
 *
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
abstract class AgaviBaseFileValidator extends AgaviValidator
{
	/**
	 * @see        AgaviValidator::initialize
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	#[\Override]
    public function initialize(AgaviContext $context, array $parameters = [], array $arguments = [], array $errors = [])
	{
		if(!isset($parameters['source'])) {
			// Default to 'files' source (PSR-7 uploaded files) now that legacy data holders are removed
			$parameters['source'] = 'files';
		}

		parent::initialize($context, $parameters, $arguments, $errors);
		
		if($this->hasParameter('mime_type') && !extension_loaded('fileinfo')) {
			throw new AgaviValidatorException('MIME type checks in file validators require the "fileinfo" PHP extension to be loaded.');
		}
	}

	/**
	 * Validates the input
	 * 
	 * @return     bool The file is valid according to given parameters.
	 * 
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function validate()
	{
		foreach($this->getArguments() as $argument) {
			$file = $this->getData($argument);

			if(!($file instanceof UploadedFileInterface)) {
				$this->throwError('argument_wrong_type');
				return false;
			}

			if($file->getError() !== UPLOAD_ERR_OK) {
				$this->throwError('upload_failed');
				return false;
			}

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
				$extOk = false;
				foreach($extensions as $extension) {
					if(strtolower((string)$extension) === strtolower($fileinfo['extension'])) { $extOk = true; break; }
				}
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
		
		return true;
	}
}

?>