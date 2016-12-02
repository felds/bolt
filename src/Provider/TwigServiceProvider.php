<?php

namespace Bolt\Provider;

use Bolt\Twig;
use Bolt\Twig\ArrayAccessSecurityProxy;
use Bolt\Twig\DumpExtension;
use Bolt\Twig\FilesystemLoader;
use Bolt\Twig\RuntimeLoader;
use Bolt\Twig\SafeEnvironment;
use Bolt\Twig\SecurityPolicy;
use Bolt\Twig\TwigExtension;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Bridge\Twig\Extension\AssetExtension;
use Symfony\Bridge\Twig\Extension\HttpFoundationExtension;

class TwigServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        if (!isset($app['twig'])) {
            $app->register(new \Silex\Provider\TwigServiceProvider());
        }

        // Twig runtime handlers
        $app['twig.runtime.bolt_admin'] = function ($app) {
            return new Twig\Runtime\AdminRuntime($app['config'], $app['stack'], $app['url_generator'], $app);
        };
        $app['twig.runtime.bolt_array'] = function () {
            return new Twig\Runtime\ArrayRuntime();
        };
        $app['twig.runtime.bolt_html'] = function ($app) {
            return new Twig\Runtime\HtmlRuntime($app);
        };
        $app['twig.runtime.bolt_image'] = function ($app) {
            return new Twig\Runtime\ImageRuntime(
                $app['config'],
                $app['url_generator'],
                $app['filesystem'],
                $app['filesystem.matcher']
            );
        };
        $app['twig.runtime.bolt_record'] = function ($app) {
            return new Twig\Runtime\RecordRuntime(
                $app['request_stack'],
                $app['pager'],
                $app['resources']->getPath('templatespath'),
                $app['config']->get('theme/templateselect/templates', [])
            );
        };
        $app['twig.runtime.bolt_routing'] = function ($app) {
            return new Twig\Runtime\RoutingRuntime($app['canonical']);
        };
        $app['twig.runtime.bolt_text'] = function ($app) {
            return new Twig\Runtime\TextRuntime($app);
        };
        $app['twig.runtime.bolt_user'] = function ($app) {
            return new Twig\Runtime\UserRuntime($app);
        };
        $app['twig.runtime.bolt_utils'] = function ($app) {
            return new Twig\Runtime\UtilsRuntime($app);
        };
        $app['twig.runtime.bolt_widget'] = function ($app) {
            return new Twig\Runtime\WidgetRuntime($app);
        };

        /** @deprecated Can be replaced when switch to Silex 2 occurs */
        if (!isset($app['twig.runtimes'])) {
            $app['twig.runtimes'] = function () {
                return [];
            };
        }
        $app['twig.runtimes'] = $app->extend(
            'twig.runtimes',
            function () {
                return [
                    Twig\Runtime\AdminRuntime::class   => 'twig.runtime.bolt_admin',
                    Twig\Runtime\ArrayRuntime::class   => 'twig.runtime.bolt_array',
                    Twig\Runtime\HtmlRuntime::class    => 'twig.runtime.bolt_html',
                    Twig\Runtime\ImageRuntime::class   => 'twig.runtime.bolt_image',
                    Twig\Runtime\RecordRuntime::class  => 'twig.runtime.bolt_record',
                    Twig\Runtime\RoutingRuntime::class => 'twig.runtime.bolt_routing',
                    Twig\Runtime\TextRuntime::class    => 'twig.runtime.bolt_text',
                    Twig\Runtime\UserRuntime::class    => 'twig.runtime.bolt_user',
                    Twig\Runtime\UtilsRuntime::class   => 'twig.runtime.bolt_utils',
                    Twig\Runtime\WidgetRuntime::class  => 'twig.runtime.bolt_widget',
                ];
            }
        );


        /** @deprecated Can be replaced when switch to Silex 2 occurs */
        if (!isset($app['twig.runtime_loader'])) {
            $app['twig.runtime_loader'] = function ($app) {
                return new RuntimeLoader($app, $app['twig.runtimes']);
            };
        }

        $app['twig.loader.bolt_filesystem'] = $app->share(
            function ($app) {
                $loader = new FilesystemLoader($app['filesystem']);

                $themePath = 'theme://' . $app['config']->get('theme/template_directory');

                $loader->addPath($themePath, 'theme');
                $loader->addPath('bolt://app/theme_defaults', 'theme');
                $loader->addPath('bolt://app/view/twig', 'bolt');

                /** @deprecated Deprecated since 3.0, to be removed in 4.0. */
                $loader->addPath($themePath);
                $loader->addPath('bolt://app/theme_defaults');
                $loader->addPath('bolt://app/view/twig');

                return $loader;
            }
        );

        // Insert our filesystem loader before native one
        $app['twig.loader'] = $app->share(
            function ($app) {
                return new \Twig_Loader_Chain(
                    [
                        $app['twig.loader.array'],
                        $app['twig.loader.bolt_filesystem'],
                        $app['twig.loader.filesystem'],
                    ]
                );
            }
        );

        $this->registerSandbox($app);

        // Add the Bolt Twig Extension.
        $app['twig'] = $app->share(
            $app->extend(
                'twig',
                function (\Twig_Environment $twig, $app) {
                    $twig->addExtension(new TwigExtension(false));
                    $twig->addExtension($app['twig.extension.asset']);
                    $twig->addExtension($app['twig.extension.http_foundation']);
                    $twig->addExtension($app['twig.extension.string_loader']);

                    if (isset($app['dump'])) {
                        $twig->addExtension($app['twig.extension.dump']);
                    }

                    $sandbox = $app['twig.extension.sandbox'];
                    $twig->addExtension($sandbox);
                    $twig->addGlobal('app', new ArrayAccessSecurityProxy($app, $sandbox));

                    /** @deprecated Can be replaced when switch to Silex 2 occurs */
                    $twig->addRuntimeLoader($app['twig.runtime_loader']);

                    return $twig;
                }
            )
        );

        $app['twig.extension.asset'] = $app->share(
            function ($app) {
                return new AssetExtension($app['asset.packages'], $app['twig.extension.http_foundation']);
            }
        );

        $app['twig.extension.http_foundation'] = $app->share(
            function ($app) {
                return new HttpFoundationExtension($app['request_stack'], $app['request_context']);
            }
        );

        $app['twig.extension.dump'] = $app->share(
            function ($app) {
                return new DumpExtension(
                    $app['dumper.cloner'],
                    $app['dumper.html'],
                    $app['users'],
                    $app['config']->get('general/debug_show_loggedoff', false)
                );
            }
        );

        $app['twig.extension.string_loader'] = $app->share(
            function () {
                return new \Twig_Extension_StringLoader();
            }
        );

        // Twig options
        $app['twig.options'] = function () use ($app) {
            $options = [];

            // Should we cache or not?
            if ($app['config']->get('general/caching/templates')) {
                $key = hash('md5', $app['config']->get('general/theme'));
                $options['cache'] = $app['resources']->getPath('cache/' . $app['environment'] . '/twig/' . $key);
            }

            if (($strict = $app['config']->get('general/strict_variables')) !== null) {
                $options['strict_variables'] = $strict;
            }

            return $options;
        };

        $app['safe_twig'] = $app->share(
            function ($app) {
                return new SafeEnvironment($app['twig'], $app['twig.extension.sandbox']);
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Application $app)
    {
    }

    protected function registerSandbox(Application $app)
    {
        $app['twig.extension.sandbox'] = $app->share(
            function ($app) {
                return new \Twig_Extension_Sandbox($app['twig.sandbox.policy']);
            }
        );

        $app['twig.sandbox.policy'] = $app->share(
            function ($app) {
                return new SecurityPolicy(
                    $app['twig.sandbox.policy.tags'],
                    $app['twig.sandbox.policy.filters'],
                    $app['twig.sandbox.policy.methods'],
                    $app['twig.sandbox.policy.properties'],
                    $app['twig.sandbox.policy.functions']
                );
            }
        );

        $app['twig.sandbox.policy.tags'] = $app->share(
            function () {
                return [
                    // Core
                    'for',
                    'if',
                    'block',
                    'filter',
                    'macro',
                    'set',
                    'spaceless',
                    'do',

                    // Translation Extension
                    'trans',
                    'transchoice',
                    'trans_default_domain',
                ];
            }
        );

        $app['twig.sandbox.policy.functions'] = $app->share(
            function () {
                return [
                    // Core
                    'max',
                    'min',
                    'range',
                    'constant',
                    'cycle',
                    'random',
                    'date',

                    // Asset Extension
                    'asset',
                    'asset_version',

                    // Bolt Extension
                    '__',
                    'backtrace',
                    'buid',
                    'canonical',
                    'countwidgets',
                    'current',
                    'data',
                    'dump',
                    'excerpt',
                    'fancybox',
                    'fields',
                    //'file_exists',
                    'firebug',
                    'first',
                    'getuser',
                    'getuserid',
                    'getwidgets',
                    'haswidgets',
                    'hattr',
                    'hclass',
                    'htmllang',
                    'image',
                    //'imageinfo',
                    'isallowed',
                    'ischangelogenabled',
                    'ismobileclient',
                    'last',
                    'link',
                    //'listtemplates',
                    'markdown',
                    //'menu',
                    'pager',
                    'popup',
                    'print',
                    'randomquote',
                    //'redirect',
                    //'request',
                    'showimage',
                    'stack',
                    'thumbnail',
                    'token',
                    'trimtext',
                    'unique',
                    'widgets',

                    // Routing Extension
                    'url',
                    'path',
                ];
            }
        );

        $app['twig.sandbox.policy.filters'] = $app->share(
            function () {
                return [
                    // Core
                    'date',
                    'date_modify',
                    'format',
                    'replace',
                    'number_format',
                    'abs',
                    'round',
                    'url_encode',
                    'json_encode',
                    'convert_encoding',
                    'title',
                    'capitalize',
                    'upper',
                    'lower',
                    'striptags',
                    'trim',
                    'nl2br',
                    'join',
                    'split',
                    'sort',
                    'merge',
                    'batch',
                    'reverse',
                    'length',
                    'slice',
                    'first',
                    'last',
                    'default',
                    'keys',
                    'escape',
                    'e',

                    // Bolt Extension
                    '__',
                    'current',
                    //'editable',
                    'excerpt',
                    'fancybox',
                    'image',
                    //'imageinfo',
                    'json_decode',
                    'localdate',
                    'localedatetime',
                    'loglevel',
                    'markdown',
                    'order',
                    'popup',
                    'preg_replace',
                    'safestring',
                    'selectfield',
                    'showimage',
                    'shuffle',
                    'shy',
                    'slug',
                    'thumbnail',
                    'trimtext',
                    'tt',
                    'twig',
                    'ucfirst',
                    //'ymllink',

                    // Form Extension
                    'humanize',

                    // Translation Extension
                    'trans',
                    'transchoice',
                ];
            }
        );

        $app['twig.sandbox.policy.methods'] = [];
        $app['twig.sandbox.policy.properties'] = [];
    }
}
