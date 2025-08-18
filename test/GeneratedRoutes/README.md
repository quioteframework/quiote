Pre-generated test routes
=========================

These PHP files are generated once via generate_symfony_routes.php with the following constraints:

* Callback-based routes are skipped entirely.
* Each test XML context (test1, test2, test_callbacks) is emitted into its own subdirectory.
* The main test conversion now targets direct AgaviRouting usage without runtime XML parsing.

Regenerate (example):

    php generate_symfony_routes.php test/sandbox/app/Config/tests/routing_simple.xml#context=test1:test/GeneratedRoutes/test1,Agavi\\Test\\Generated\\Test1

After full migration the original XML test files can be deleted.
