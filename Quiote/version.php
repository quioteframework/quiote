<?php
/**
 * Version initialization script.
 * Keep the major/minor/micro values here in step with the release tag and
 * CHANGELOG.md — `quiote.version` is what the `about` command reports and what
 * APCuConfigCache stamps into a cached config, so a stale value here surfaces
 * as a wrong framework version at runtime.
 * @since      1.0.0
 */

\Quiote\Config\Config::set('quiote.name', 'Quiote');

\Quiote\Config\Config::set('quiote.major_version', '4');
\Quiote\Config\Config::set('quiote.minor_version', '0');
\Quiote\Config\Config::set('quiote.micro_version', '0');
\Quiote\Config\Config::set('quiote.status', '');
\Quiote\Config\Config::set('quiote.branch', 'main');

// Config::has() is true for a directive set to '', and a stable release leaves
// quiote.status empty — test the value, not its presence, or every version
// string comes out with a trailing '-'.
\Quiote\Config\Config::set('quiote.version',
	\Quiote\Config\Config::getString('quiote.major_version') . '.' .
	\Quiote\Config\Config::getString('quiote.minor_version') . '.' .
	\Quiote\Config\Config::getString('quiote.micro_version') .
	(\Quiote\Config\Config::getString('quiote.status') !== ''
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
