<?php
namespace Quiote\Exception;
/**
 * Exception is the base class for all Quiote related exceptions and
 * provides an additional method for printing up a detailed view of an
 * exception.
 * @since      1.0.0
 * @version    1.0.0
 */
class QuioteException extends \Exception
{
	public function __construct(string $message = '', private readonly int|string $mixedCode = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, is_int($this->mixedCode) ? $this->mixedCode : 0, $previous);
	}

	/** Returns the original code, which may be a string (e.g. a PDO SQLSTATE like "42P01"). */
	public function getOriginalCode(): int|string
	{
		return $this->mixedCode;
	}

	/**
	 * Returns a fixed stack trace in case the original one from the exception
	 * does not contain the origin as the first entry in the trace array, which
	 * appears to happen from time to time or with certain PHP/XDebug versions.
	 * @param      \Throwable $e The exception to pull the trace from.
	 * @param      ?\Throwable $next Optionally, the next exception to display (pulled
	 *                       from Exception::getPrevious() and displayed in
	 *                       reverse order), which will then result in identical
	 *                       parts of the stack trace not being returned.
	 * @return     array The trace containing the exception origin as first item.
	 * @since      1.0.0
	 */
	public static function getFixedTrace(\Throwable $e, ?\Throwable $next = null)
	{
		// fix stack trace in case it doesn't contain the exception origin as the first entry
		$fixedTrace = $e->getTrace();
		
		if(!isset($fixedTrace[0]['file']) || !($fixedTrace[0]['file'] == $e->getFile() && $fixedTrace[0]['line'] == $e->getLine())) {
			$fixedTrace = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $fixedTrace);
		}
		
		if($next) {
			$nextTrace = self::getFixedTrace($next);
			foreach($fixedTrace as $i => $fixedTraceItem) {
				if($fixedTraceItem == $nextTrace[1]) {
					$fixedTrace = array_slice($fixedTrace, 0, $i);
					break;
				}
			}
		}
		
		return $fixedTrace;
	}
	
	/**
	 * Build a list of parameters passed to a method. Example:
	 * array([object Filter], 'baz' => array(1, 2), 'log' => [resource stream])
	 * @param      array $params An (associative) array of variables.
	 * @param      bool $html Whether or not to style and encode for HTML output.
	 * @return     string A string, possibly formatted using HTML "em" tags.
	 * @since      1.0.0
	 */
	public static function buildParamList($params, $html = true, $level = 1)
	{
		$retval = [];
		foreach($params as $key => $param) {
			if(is_string($key)) {
				if(preg_match('/^(.{5}).{2,}(.{5})$/us', $key, $matches)) {
					$key = $matches[1] . '…' . $matches[2];
				}
				$key = var_export($key, true) . ' => ';
				if($html) {
					$key = htmlspecialchars($key);
				}
			} else {
				$key = '';
			}
			switch(gettype($param)) {
				case 'array':
					$retval[] = $key . 'array(' . ($level < 2 ? self::buildParamList($param, $html, ++$level) : (count($param) ? '…' : '')) . ')';
					break;
				case 'object':
					if($html) {
						$retval[] = $key . '[object <em>' . $param::class . '</em>]';
					} else {
						$retval[] = $key . '[object ' . $param::class . ']';
					}
					break;
				case 'resource':
					if($html) {
						$retval[] = $key . '[resource <em>' . htmlspecialchars(get_resource_type($param)) . '</em>]';
					} else {
						$retval[] = $key . '[resource ' . get_resource_type($param) . ']';
					}
					break;
				case 'string':
					$val = $param;
					if(preg_match('/^(.{20}).{3,}(.{20})$/us', $val, $matches)) {
						$val = $matches[1] . ' … ' . $matches[2];
					}
					$val = var_export($val, true);
					if($html) {
						$retval[] = $key . htmlspecialchars($val);
					} else {
						$retval[] = $key . $val;
					}
					break;
				default:
					if($html) {
						$retval[] = $key . htmlspecialchars(var_export($param, true));
					} else {
						$retval[] = $key . var_export($param, true);
					}
			}
		}
		return implode(', ', $retval);
	}
	
	/**
	 * Perform PHP syntax highlighting on the given file.
	 * @param      string $filepath The path of the file to highlight.
	 * @return     array An 0-indexed array of HTML-highlighted code lines.
	 * @since      1.0.0
	 */
	public static function highlightFile($filepath)
	{
		return self::highlightString(file_get_contents($filepath));
	}
	
	/**
	 * Perform PHP syntax highlighting on the given code string.
	 * @param      string $code The PHP code to highlight.
	 * @return     array An 0-indexed array of HTML-highlighted code lines.
	 * @since      1.0.0
	 */
	public static function highlightString($code)
	{
		$code = highlight_string(str_replace('	', '  ', $code), true);
		// time to cleanup this highlighted string
		// first, drop all newlines (we'll explode by "<br />")
		$code = str_replace(["\r\n", "\n", "\r"], ['', '', ''], $code);
		// second, remove start and end wrappers and replace &nbsp; with numeric entity
		$code = str_replace([sprintf('<pre><code style="color: %s">', ini_get('highlight.html')), '</code></pre>', '&nbsp;'], ['', '', '&#160;'], $code);
		// make an array of lines
		$code = explode('<br />', $code);
		// iterate and cleanup each line
		$remember = null;
		foreach($code as &$line) {
			// we need at least an nbsp for empty lines
			if($line == '') {
				$line = '&#160;';
			}
			
			// drop leading </span>
			if(str_starts_with($line, '</span>')) {
				$line = substr($line, 7);
				// no style to carry over from previous line(s)
				$remember = null;
			}
			
			// prepend style from previous line if we have one
			if($remember) {
				$line = sprintf('<span style="color: %s">%s', $remember, $line);
			}
			
			$openingSpanPos = strpos($line, '<span');
			$openingSpanRPos = strrpos($line, '<span');
			$closingSpanPos = strpos($line, '</span>');
			$closingSpanRPos = strrpos($line, '</span>');
			
			$balanced = (($openingSpanCount = preg_match_all('#<span#', $line, $matches)) == ($closingSpanCount = preg_match_all('#</span>#', $line, $matches)));
			if($balanced && $openingSpanPos !== false && $openingSpanPos < $closingSpanPos) {
				// already balanced, no further cleanup necessary
				$remember = null;
				continue;
			}
			
			if(str_ends_with($line, '</span>')) {
				// discard previous style if style terminates in this line
				$remember = null;
			} else {
				// otherwise, remember last style from this line if there is one
				if($openingSpanRPos !== false) {
					// must remember previous color; 20 is the length of '<span style="color: '
					// we're using strpos since someone could set the colors to "#333" or "red" through php.ini, so we don't know the length
					$remember = substr($line, $openingSpanRPos + 20, strpos($line, '"', $openingSpanRPos + 20) - ($openingSpanRPos + 20));
				}
				// append closing tag
				$line .= '</span>';
				$closingSpanCount++;
			}
			
			// in case things still are not right...
			// can happen for instance when the first line of the file is HTML and we drop the first span, since that is a wrapper for everything
			if($openingSpanCount < $closingSpanCount) {
				$line = sprintf('<span style="color: %s">%s%s', ini_get('highlight.html'), str_repeat('<span style="color: ' . ini_get('highlight.html') . '">', $closingSpanCount - $openingSpanCount - 1), $line);
			}
			if($closingSpanCount < $openingSpanCount) {
				$line = sprintf('%s%s', $line, str_repeat('</span>', $openingSpanCount - $closingSpanCount));
			}
		}
		
		return $code;
	}
	
}

?>