<?php
/**
 * Version initialization script.
 * @since      1.0.0
 * @version    1.0.0
 */

 use Quiote\Config\Config;

\Quiote\Config\Config::set('quiote.name', 'Quiote');

\Quiote\Config\Config::set('quiote.major_version', '2');
\Quiote\Config\Config::set('quiote.minor_version', '0');
\Quiote\Config\Config::set('quiote.micro_version', '0');
\Quiote\Config\Config::set('quiote.status', 'dev');
\Quiote\Config\Config::set('quiote.branch', 'php84');

\Quiote\Config\Config::set('quiote.version',
	\Quiote\Config\Config::getString('quiote.major_version') . '.' .
	\Quiote\Config\Config::getString('quiote.minor_version') . '.' .
	\Quiote\Config\Config::getString('quiote.micro_version') .
	(\Quiote\Config\Config::has('quiote.status')
		? '-' . \Quiote\Config\Config::getString('quiote.status')
		: '')
);

\Quiote\Config\Config::set('quiote.release',
	\Quiote\Config\Config::getString('quiote.name') . '/' .
	\Quiote\Config\Config::getString('quiote.version')
);

\Quiote\Config\Config::set('quiote.url', 'https://github.com/quioteframework/quiote');

\Quiote\Config\Config::set('quiote_info',
	\Quiote\Config\Config::getString('quiote.release') . ' (' .
	\Quiote\Config\Config::getString('quiote.url') . ')'
);

?>