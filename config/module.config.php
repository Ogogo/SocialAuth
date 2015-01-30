<?php
return array(
    'controllers' => array(
        'factories' => array(
            'SocialAuth-HybridAuth' => 'SocialAuth\Service\HybridAuthControllerFactory',
            'SocialAuth-User' => 'SocialAuth\Service\UserControllerFactory',
        ),
    ),
    'controller_plugins' => array(
        'invokables' => array(
            'socialauthprovider' => 'SocialAuth\Controller\Plugin\SocialAuthProvider',
        ),
    ),
    'router' => array(
        'routes' => array(
            'social-auth-hauth' => array(
                'type'    => 'Literal',
                'priority' => 2000,
                'options' => array(
                    'route' => '/social-auth/hauth',
                    'defaults' => array(
                        'controller' => 'SocialAuth-HybridAuth',
                        'action'     => 'index',
                    ),
                ),
            ),
            'social-auth-user' => array(
                'type' => 'Literal',
                'priority' => 2000,
                'options' => array(
                    'route' => '/user',
                    'defaults' => array(
                        'controller' => 'zfcuser',
                        'action'     => 'index',
                    ),
                ),
                'may_terminate' => true,
                'child_routes' => array(
                    'authenticate' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/authenticate',
                            'defaults' => array(
                                'controller' => 'zfcuser',
                                'action'     => 'authenticate',
                            ),
                        ),
                        'may_terminate' => true,
                        'child_routes' => array(
                            'provider' => array(
                                'type' => 'Segment',
                                'options' => array(
                                    'route' => '/:provider',
                                    'constraints' => array(
                                        'provider' => '[a-zA-Z][a-zA-Z0-9_-]+',
                                    ),
                                    'defaults' => array(
                                        'controller' => 'SocialAuth-User',
                                        'action' => 'provider-authenticate',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'login' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/login',
                            'defaults' => array(
                                'controller' => 'SocialAuth-User',
                                'action'     => 'login',
                            ),
                        ),
                        'may_terminate' => true,
                        'child_routes' => array(
                            'provider' => array(
                                'type' => 'Segment',
                                'options' => array(
                                    'route' => '/:provider',
                                    'constraints' => array(
                                        'provider' => '[a-zA-Z][a-zA-Z0-9_-]+',
                                    ),
                                    'defaults' => array(
                                        'controller' => 'SocialAuth-User',
                                        'action' => 'provider-login',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'logout' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/logout',
                            'defaults' => array(
                                'controller' => 'SocialAuth-User',
                                'action'     => 'logout',
                            ),
                        ),
                    ),
                    'register' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/register',
                            'defaults' => array(
                                'controller' => 'SocialAuth-User',
                                'action'     => 'register',
                            ),
                        ),
                    ),
                    'add-provider' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/add-provider',
                            'defaults' => array(
                                'controller' => 'SocialAuth-User',
                                'action'     => 'add-provider',
                            ),
                        ),
                        'child_routes' => array(
                            'provider' => array(
                                'type' => 'Segment',
                                'options' => array(
                                    'route' => '/:provider',
                                    'constraints' => array(
                                        'provider' => '[a-zA-Z][a-zA-Z0-9_-]+',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),
    'service_manager' => array(
        'aliases' => array(
            'SocialAuth_ZendDbAdapter' => 'Zend\Db\Adapter\Adapter',
            'SocialAuth_ZendSessionManager' => 'Zend\Session\SessionManager',
        ),
        'factories' => array(
            'HybridAuth' => 'SocialAuth\Service\HybridAuthFactory',
            'SocialAuth-ModuleOptions' => 'SocialAuth\Service\ModuleOptionsFactory',
            'SocialAuth-UserProviderMapper' => 'SocialAuth\Service\UserProviderMapperFactory',
            'SocialAuth-AuthenticationAdapterChain' => 'SocialAuth\Service\AuthenticationAdapterChainFactory',
            'SocialAuth\Authentication\Adapter\HybridAuth' => 'SocialAuth\Service\HybridAuthAdapterFactory',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'socialSignInButton' => 'SocialAuth\View\Helper\SocialSignInButton',
        ),
        'factories' => array(
            'UserProvider'   => 'SocialAuth\Service\UserProviderViewHelperFactory',
        ),
    ),
    'view_manager' => array(
        'template_path_stack' => array(
            'social-auth' => __DIR__ . '/../view'
        ),
    ),
);
