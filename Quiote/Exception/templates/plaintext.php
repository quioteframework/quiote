<?php
/**
 * Plain text exception template
 * @since      1.0.0
 * @version    1.0.0
 */

use Quiote\Config\Config;
use Quiote\Exception\QuioteException;

// we're not supposed to display errors
// let's throw the exception so it shows up in error logs
if(!ini_get('display_errors')) {
	throw $e;
}

if(!headers_sent()) {
	header('Content-Type: text/plain');	
}

$cols = 80;
if(!defined('STDOUT') || (function_exists('posix_isatty') && !posix_isatty(STDOUT))) {
	// if output is redirected, do not wrap lines after just 80 characters
	$cols = false;
} elseif(file_exists('/bin/stty') && is_executable('/bin/stty') && $sttySize = exec('/bin/stty size 2>/dev/null')) {
	// grab the terminal width for line wrapping if possible
	[, $cols] = explode(' ', $sttySize);
}

?>

#####################
# Application Error #
#####################

<?php if(count($exceptions) > 1): ?>
<?php $msg = sprintf('The %s was caused by %s. A full chain of exceptions is listed below.', $e::class, ((count($exceptions) == 2) ? 'another exception' : 'other exceptions')); echo $cols ? wordwrap($msg, $cols, "\n") : $msg; ?>


<?php endif; ?>
<?php foreach($exceptions as $ei => $e): ?>

  <?php echo $e::class; ?> 
==<?php echo str_repeat("=", strlen($e::class)); ?>==

<?php
$lines = explode("\n", trim((string) $e->getMessage()));
foreach($lines as $line):
?>
  <?php echo $cols ? wordwrap($line, $cols-2, "\n  ", true) : $line; ?>

<?php endforeach; ?>

  Stack Trace
  -----------
<?php
$i = 0;
$traceLines = QuioteException::getFixedTrace($e, $exceptions[$ei+1] ?? null);
$traceCount = count($traceLines);
foreach($traceLines as $trace) {
	$i++;
	echo sprintf("  %" . strlen($traceCount) . "s: ", $i);
	if(isset($trace['file'])) {
		$msg = $trace['file'] . (isset($trace['line']) ? ':' . $trace['line'] : ''); echo $cols ? wordwrap($msg, $cols - 4 - strlen($traceCount), "\n" . str_repeat(' ', 4 + strlen($traceCount)), true) : $msg;
	} else {
		echo "Unknown file";
	}
	echo "\n";
}

endforeach;
?>


  Version Information
=======================

  Quiote:     <?php echo $cols ? wordwrap((string) Config::get('quiote.version'), $cols-13, "\n             ", true) : Config::get('quiote.version'); ?>

  PHP:       <?php echo $cols ? wordwrap(phpversion(), $cols-13, "\n             ", true) : phpversion(); ?>

  System:    <?php echo $cols ? wordwrap(php_uname(), $cols-13, "\n             ", true): php_uname(); ?>

  Timestamp: <?php echo $cols ? wordwrap(gmdate(\DateTime::ATOM), $cols-13, "\n             ", true) : gmdate(\DateTime::ATOM); ?>


