<?php

declare(strict_types=1);

use Cline\Tenancy\Enums\IsolationMode;
use Cline\Tenancy\Models\Landlord;
use Cline\Tenancy\Models\Tenant;
use Cline\Tenancy\Resolvers\AuthenticatedLandlordResolver;
use Cline\Tenancy\Resolvers\AuthenticatedTenantResolver;
use Cline\Tenancy\Resolvers\DomainLandlordResolver;
use Cline\Tenancy\Resolvers\DomainTenantResolver;
use Cline\Tenancy\Resolvers\PathLandlordResolver;
use Cline\Tenancy\Resolvers\PathTenantResolver;
use Cline\Tenancy\Resolvers\SessionLandlordResolver;
use Cline\Tenancy\Resolvers\SessionTenantResolver;
use Cline\Tenancy\Resolvers\SubdomainLandlordResolver;
use Cline\Tenancy\Resolvers\SubdomainTenantResolver;
use Cline\Tenancy\Tasks\PrefixCacheTask;
use Cline\Tenancy\Tasks\SwitchTenantDatabaseTask;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Model
    |--------------------------------------------------------------------------
    |
    | This option defines the Eloquent model that represents a tenant within
    | your application. The model is used throughout the tenancy system to
    | identify, load, and switch the active tenant context for each request.
    |
    */

    'tenant_model' => Tenant::class,

    /*
    |--------------------------------------------------------------------------
    | Landlord Model
    |--------------------------------------------------------------------------
    |
    | This option defines the Eloquent model that represents a landlord, which
    | is the top-level organisational entity that owns one or more tenants.
    | The landlord model participates in domain resolution and context scoping
    | in the same way the tenant model does, but at a higher level of the
    | hierarchy.
    |
    */

    'landlord_model' => Landlord::class,

    /*
    |--------------------------------------------------------------------------
    | Primary Key Type
    |--------------------------------------------------------------------------
    |
    | Here you may specify the type of primary key used by your tenant and
    | landlord models. This value is read from the TENANCY_PRIMARY_KEY_TYPE
    | environment variable and defaults to a standard auto-incrementing integer
    | column named "id". You may change this to "uuid" or "ulid" if your
    | models use those identifier strategies instead.
    |
    | Supported: "id", "uuid", "ulid"
    |
    */

    'primary_key_type' => env('TENANCY_PRIMARY_KEY_TYPE', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Tenancy Database Connection
    |--------------------------------------------------------------------------
    |
    | This option specifies the database connection that the tenancy system
    | itself should use when performing its own queries, such as resolving
    | tenants and landlords from the database. When set to null, the default
    | application connection defined in config/database.php will be used.
    |
    */

    'connection' => env('TENANCY_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Here you may customise the names of the database tables used by the
    | tenancy system. Each table name may be overridden via the corresponding
    | environment variable, allowing you to avoid conflicts with existing
    | tables in your application's schema.
    |
    */

    'table_names' => [

        /*
        |--------------------------------------------------------------------------
        | Landlords Table
        |--------------------------------------------------------------------------
        |
        | The name of the database table that stores landlord records. Defaults
        | to "landlords" but may be changed via the TENANCY_LANDLORDS_TABLE
        | environment variable.
        |
        */

        'landlords' => env('TENANCY_LANDLORDS_TABLE', 'landlords'),

        /*
        |--------------------------------------------------------------------------
        | Landlord Domains Table
        |--------------------------------------------------------------------------
        |
        | The name of the database table that stores domain records associated
        | with landlords. Defaults to "landlord_domains" but may be changed via
        | the TENANCY_LANDLORD_DOMAINS_TABLE environment variable.
        |
        */

        'landlord_domains' => env('TENANCY_LANDLORD_DOMAINS_TABLE', 'landlord_domains'),

        /*
        |--------------------------------------------------------------------------
        | Tenants Table
        |--------------------------------------------------------------------------
        |
        | The name of the database table that stores tenant records. Defaults
        | to "tenants" but may be changed via the TENANCY_TENANTS_TABLE
        | environment variable.
        |
        */

        'tenants' => env('TENANCY_TENANTS_TABLE', 'tenants'),

        /*
        |--------------------------------------------------------------------------
        | Tenant Domains Table
        |--------------------------------------------------------------------------
        |
        | The name of the database table that stores domain records associated
        | with tenants. Defaults to "tenant_domains" but may be changed via
        | the TENANCY_TENANT_DOMAINS_TABLE environment variable.
        |
        */

        'tenant_domains' => env('TENANCY_TENANT_DOMAINS_TABLE', 'tenant_domains'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy Bootstrap Tasks
    |--------------------------------------------------------------------------
    |
    | When a tenant is resolved and made active, the tenancy system will run
    | each of the tasks listed here in sequence. Tasks are responsible for
    | configuring the application environment for the current tenant, such as
    | switching the database connection or scoping the cache. You may add your
    | own task classes to this array to extend the bootstrapping pipeline.
    |
    */

    'tasks' => [
        SwitchTenantDatabaseTask::class,
        PrefixCacheTask::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration Mapping
    |--------------------------------------------------------------------------
    |
    | Configuration mapping allows you to dynamically override Laravel config
    | values when a tenant becomes active. Each entry in the "mappings" array
    | should map a tenant attribute to a dot-notation config key. This is
    | useful for applying tenant-specific mail, storage, or service settings
    | without writing custom bootstrap tasks.
    |
    */

    'config_mapping' => [

        /*
        |--------------------------------------------------------------------------
        | Mappings
        |--------------------------------------------------------------------------
        |
        | Define your tenant attribute to configuration key mappings here. Each
        | entry should be an associative array where the key is a dot-notation
        | config path and the value is the corresponding attribute on the tenant
        | model. The mappings array is empty by default.
        |
        */

        'mappings' => [],

    ],

    /*
    |--------------------------------------------------------------------------
    | Isolation Mode
    |--------------------------------------------------------------------------
    |
    | This option controls the default database isolation strategy used for
    | tenants. In "shared_database" mode, all tenants share a single database
    | and their data is separated by a scoped foreign key. In "isolated"
    | mode, each tenant is given its own dedicated database connection.
    |
    | Supported: IsolationMode::SHARED_DATABASE, IsolationMode::ISOLATED
    |
    */

    'isolation' => IsolationMode::SHARED_DATABASE->value,

    /*
    |--------------------------------------------------------------------------
    | Tenant Database Configuration
    |--------------------------------------------------------------------------
    |
    | When using isolated database mode, the options below are used to
    | configure the tenant's dedicated database connection. You may specify
    | the named connection from your config/database.php file that should
    | be switched to when a tenant is bootstrapped.
    |
    */

    'database' => [

        /*
        |--------------------------------------------------------------------------
        | Tenant Database Connection
        |--------------------------------------------------------------------------
        |
        | The name of the database connection that should be used for the active
        | tenant when operating in isolated database mode. When set to null, the
        | tenancy system will attempt to derive a connection name automatically
        | from the tenant's identifier.
        |
        */

        'connection' => null,

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how the tenancy system scopes cache entries to the
    | active tenant. The PrefixCacheTask uses these values to namespace every
    | cache key, preventing one tenant's cached data from bleeding into
    | another tenant's cache space.
    |
    */

    'cache' => [

        /*
        |--------------------------------------------------------------------------
        | Cache Key Prefix
        |--------------------------------------------------------------------------
        |
        | This string is prepended to every cache key written while a tenant is
        | active. It is combined with the delimiter and the tenant's identifier
        | to form the full namespaced key, e.g. "tenant:42:some-cache-key".
        |
        */

        'prefix' => 'tenant',

        /*
        |--------------------------------------------------------------------------
        | Cache Key Delimiter
        |--------------------------------------------------------------------------
        |
        | The character used to separate the prefix, tenant identifier, and the
        | original cache key when constructing the final namespaced cache key.
        | The default delimiter is a colon, which follows the conventional Redis
        | key naming pattern.
        |
        */

        'delimiter' => ':',

    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolver Configuration
    |--------------------------------------------------------------------------
    |
    | This section configures how the tenancy system identifies the active
    | tenant for each incoming request. You may define an ordered list of
    | resolver classes; they will be attempted in sequence and the first
    | successful resolution will be used. You may also configure the HTTP
    | header, path segment, session key, and user attribute inspected by
    | the individual resolver implementations.
    |
    */

    'resolver' => [

        /*
        |--------------------------------------------------------------------------
        | Tenant Resolvers
        |--------------------------------------------------------------------------
        |
        | The ordered list of resolver classes that will be used to identify the
        | current tenant from the incoming HTTP request. Resolvers are attempted
        | in the order listed and the first one to return a tenant wins. Remove
        | any resolvers that are not relevant to your application's routing
        | strategy.
        |
        */

        'resolvers' => [
            DomainTenantResolver::class,
            SubdomainTenantResolver::class,
            PathTenantResolver::class,
            AuthenticatedTenantResolver::class,
            SessionTenantResolver::class,
        ],

        /*
        |--------------------------------------------------------------------------
        | Tenant Header
        |--------------------------------------------------------------------------
        |
        | When resolving tenants via an HTTP header, this option defines the name
        | of that header. This is typically used for API contexts where the client
        | explicitly identifies the tenant it is acting on behalf of.
        |
        */

        'header' => 'X-Tenant',

        /*
        |--------------------------------------------------------------------------
        | Central Domains
        |--------------------------------------------------------------------------
        |
        | Requests originating from these domains will not be subject to tenant
        | resolution and will be treated as belonging to the central application.
        | You may list your primary application domains, admin panels, or any
        | other domains that should bypass the tenancy pipeline entirely.
        |
        */

        'central_domains' => [],

        /*
        |--------------------------------------------------------------------------
        | Path Segment
        |--------------------------------------------------------------------------
        |
        | When using the PathTenantResolver, this option determines which segment
        | of the request URI is inspected for the tenant identifier. The value is
        | one-based, so a value of 1 corresponds to the first path segment after
        | the domain, e.g. "https://example.com/{tenant}/dashboard".
        |
        */

        'path_segment' => 1,

        /*
        |--------------------------------------------------------------------------
        | Session Key
        |--------------------------------------------------------------------------
        |
        | When using the SessionTenantResolver, the tenant identifier is read
        | from the session using this key. Ensure that the session is populated
        | with the correct tenant identifier before the resolver is invoked,
        | typically during the authentication or login flow.
        |
        */

        'session_key' => 'tenant',

        /*
        |--------------------------------------------------------------------------
        | User Attribute
        |--------------------------------------------------------------------------
        |
        | When using the AuthenticatedTenantResolver, this option specifies the
        | attribute on the authenticated user model that holds the tenant's
        | identifier. When set to null, the resolver will attempt to call a
        | getTenant() method on the user model instead.
        |
        */

        'user_attribute' => null,

    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy Context
    |--------------------------------------------------------------------------
    |
    | These options govern the strictness of the tenancy context at runtime.
    | Enabling these guards helps catch misconfigured routes or missing tenant
    | resolution early in development, rather than allowing requests to proceed
    | with an ambiguous or incoherent tenancy state.
    |
    */

    'context' => [

        /*
        |--------------------------------------------------------------------------
        | Require Resolved Tenant
        |--------------------------------------------------------------------------
        |
        | When set to true, the tenancy system will throw an exception if a
        | tenant-scoped route is accessed without a tenant having been resolved.
        | Disable this only if your application deliberately permits unauthenticated
        | or unscoped access to tenant-aware routes.
        |
        */

        'require_resolved' => true,

        /*
        |--------------------------------------------------------------------------
        | Enforce Coherence
        |--------------------------------------------------------------------------
        |
        | When set to true, the tenancy system will verify that the resolved
        | tenant is consistent across all resolution sources present in a request,
        | such as the domain, session, and authenticated user. A mismatch between
        | sources will result in an exception, guarding against spoofing or
        | session fixation scenarios.
        |
        */

        'enforce_coherence' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Landlord Configuration
    |--------------------------------------------------------------------------
    |
    | This section mirrors the top-level tenancy options but applies specifically
    | to landlord resolution and bootstrapping. Landlords sit above tenants in
    | the organisational hierarchy and may have their own isolation mode, database
    | connection, resolver pipeline, bootstrap tasks, and domain lookup settings.
    |
    */

    'landlord' => [

        /*
        |--------------------------------------------------------------------------
        | Landlord Isolation Mode
        |--------------------------------------------------------------------------
        |
        | This option controls the database isolation strategy used for landlords,
        | independently of the tenant isolation mode. In most applications this
        | will remain at the default of "shared_database".
        |
        | Supported: IsolationMode::SHARED_DATABASE, IsolationMode::ISOLATED
        |
        */

        'isolation' => IsolationMode::SHARED_DATABASE->value,

        /*
        |--------------------------------------------------------------------------
        | Landlord Database Configuration
        |--------------------------------------------------------------------------
        |
        | When using isolated database mode for landlords, the options below are
        | used to configure the dedicated database connection. You may specify
        | a named connection from your config/database.php file.
        |
        */

        'database' => [

            /*
            |--------------------------------------------------------------------------
            | Landlord Database Connection
            |--------------------------------------------------------------------------
            |
            | The name of the database connection that should be used for the active
            | landlord when operating in isolated database mode. When set to null,
            | the tenancy system will derive a connection name automatically from
            | the landlord's identifier.
            |
            */

            'connection' => null,

        ],

        /*
        |--------------------------------------------------------------------------
        | Landlord Resolver Configuration
        |--------------------------------------------------------------------------
        |
        | This section configures how the tenancy system identifies the active
        | landlord for each incoming request. The resolver pipeline for landlords
        | operates identically to the tenant resolver pipeline defined above, but
        | targets the landlord model and its associated domains.
        |
        */

        'resolver' => [

            /*
            |--------------------------------------------------------------------------
            | Landlord Resolvers
            |--------------------------------------------------------------------------
            |
            | The ordered list of resolver classes that will be used to identify the
            | current landlord from the incoming HTTP request. Resolvers are attempted
            | in the order listed and the first successful result is used. Remove any
            | resolvers not relevant to your application's landlord routing strategy.
            |
            */

            'resolvers' => [
                DomainLandlordResolver::class,
                SubdomainLandlordResolver::class,
                PathLandlordResolver::class,
                AuthenticatedLandlordResolver::class,
                SessionLandlordResolver::class,
            ],

            /*
            |--------------------------------------------------------------------------
            | Landlord Header
            |--------------------------------------------------------------------------
            |
            | When resolving landlords via an HTTP header, this option defines the
            | name of that header. This is useful for API contexts where the client
            | must explicitly identify the landlord context alongside the tenant.
            |
            */

            'header' => 'X-Landlord',

            /*
            |--------------------------------------------------------------------------
            | Landlord Central Domains
            |--------------------------------------------------------------------------
            |
            | Requests originating from these domains will bypass landlord resolution
            | entirely and will be treated as belonging to the central application.
            | This list functions identically to the tenant-level central_domains
            | option but applies to the landlord resolver pipeline.
            |
            */

            'central_domains' => [],

            /*
            |--------------------------------------------------------------------------
            | Landlord Path Segment
            |--------------------------------------------------------------------------
            |
            | When using the PathLandlordResolver, this option determines which URI
            | segment is inspected for the landlord identifier. The value is one-based,
            | so 1 corresponds to the first segment after the domain.
            |
            */

            'path_segment' => 1,

            /*
            |--------------------------------------------------------------------------
            | Landlord Session Key
            |--------------------------------------------------------------------------
            |
            | When using the SessionLandlordResolver, the landlord identifier is read
            | from the session under this key. Populate this session value during your
            | landlord authentication or login flow before the resolver runs.
            |
            */

            'session_key' => 'landlord',

            /*
            |--------------------------------------------------------------------------
            | Landlord User Attribute
            |--------------------------------------------------------------------------
            |
            | When using the AuthenticatedLandlordResolver, this option specifies the
            | attribute on the authenticated user model that holds the landlord's
            | identifier. When set to null, the resolver will call getLandlord() on
            | the user model instead.
            |
            */

            'user_attribute' => null,

        ],

        /*
        |--------------------------------------------------------------------------
        | Landlord Bootstrap Tasks
        |--------------------------------------------------------------------------
        |
        | When a landlord is resolved and made active, the tenancy system will run
        | each of the tasks listed here in sequence. This array is empty by default
        | as landlords often share the central application's database and do not
        | require the same bootstrapping steps as tenants. You may add your own
        | task classes here to customise the landlord bootstrapping pipeline.
        |
        */

        'tasks' => [],

        /*
        |--------------------------------------------------------------------------
        | Landlord Configuration Mapping
        |--------------------------------------------------------------------------
        |
        | Configuration mapping for landlords follows the same convention as the
        | top-level config_mapping option but applies when a landlord is the active
        | context. Each entry in the "mappings" array maps a landlord attribute to
        | a dot-notation Laravel config key.
        |
        */

        'config_mapping' => [

            /*
            |--------------------------------------------------------------------------
            | Landlord Mappings
            |--------------------------------------------------------------------------
            |
            | Define your landlord attribute to configuration key mappings here.
            | The array is empty by default. See the top-level config_mapping option
            | for a description of the expected format.
            |
            */

            'mappings' => [],

        ],

        /*
        |--------------------------------------------------------------------------
        | Landlord Domain Lookup
        |--------------------------------------------------------------------------
        |
        | These options control how the tenancy system resolves landlords from
        | an incoming request domain. Domain lookups may be performed against
        | the database directly or cached for improved performance on high-traffic
        | applications.
        |
        */

        'domain_lookup' => [

            /*
            |--------------------------------------------------------------------------
            | Use Table for Landlord Domain Lookup
            |--------------------------------------------------------------------------
            |
            | When set to true, the system will query the landlord domains table to
            | resolve a landlord from the current request hostname. Set this to false
            | if you resolve landlords exclusively via subdomains or other strategies
            | that do not require a database lookup.
            |
            */

            'use_table' => true,

            /*
            |--------------------------------------------------------------------------
            | Landlord Domain Lookup Cache
            |--------------------------------------------------------------------------
            |
            | To avoid a database query on every request, you may enable caching for
            | landlord domain lookups. When enabled, resolved landlord domain records
            | are stored in the cache for the configured number of seconds.
            |
            */

            'cache' => [

                /*
                |--------------------------------------------------------------------------
                | Enable Landlord Domain Cache
                |--------------------------------------------------------------------------
                |
                | Set to true to cache landlord domain lookup results. This is strongly
                | recommended in production environments where domain resolution occurs
                | on every request, as it eliminates a database query per request.
                |
                */

                'enabled' => false,

                /*
                |--------------------------------------------------------------------------
                | Landlord Domain Cache TTL
                |--------------------------------------------------------------------------
                |
                | The number of seconds a cached landlord domain lookup result should
                | be retained before expiring. After expiry the next request will
                | re-query the database and refresh the cached value.
                |
                */

                'ttl_seconds' => 60,

                /*
                |--------------------------------------------------------------------------
                | Landlord Domain Cache Store
                |--------------------------------------------------------------------------
                |
                | The name of the cache store that should be used to persist landlord
                | domain lookup results. When set to null, the default cache store
                | defined in config/cache.php will be used.
                |
                */

                'store' => null,

                /*
                |--------------------------------------------------------------------------
                | Landlord Domain Cache Prefix
                |--------------------------------------------------------------------------
                |
                | All cached landlord domain lookup entries will be stored under keys
                | that begin with this prefix. This helps avoid key collisions with
                | other entries in the same cache store.
                |
                */

                'prefix' => 'tenancy:domain:landlord:',

            ],

        ],

        /*
        |--------------------------------------------------------------------------
        | Landlord Queue Configuration
        |--------------------------------------------------------------------------
        |
        | These options control how landlord context is propagated to queued jobs.
        | The prefix and delimiter are combined with the landlord's identifier to
        | form a namespaced queue name, ensuring that queued work is associated
        | with the correct landlord when it is processed.
        |
        */

        'queue' => [

            /*
            |--------------------------------------------------------------------------
            | Landlord Queue Prefix
            |--------------------------------------------------------------------------
            |
            | This string is prepended to queue names when dispatching jobs within
            | a landlord context. It is joined with the delimiter and the landlord's
            | identifier to form the full queue name.
            |
            */

            'prefix' => 'landlord',

            /*
            |--------------------------------------------------------------------------
            | Landlord Queue Delimiter
            |--------------------------------------------------------------------------
            |
            | The character used to separate the prefix and the landlord identifier
            | when constructing the namespaced queue name. Defaults to a colon,
            | following the conventional queue naming pattern.
            |
            */

            'delimiter' => ':',

        ],

        /*
        |--------------------------------------------------------------------------
        | Landlord Queue Payload Key
        |--------------------------------------------------------------------------
        |
        | When a job is dispatched within a landlord context, the landlord's
        | identifier is serialised into the job payload under this key. The queue
        | worker uses this key to restore the landlord context before executing
        | the job.
        |
        */

        'payload_key' => 'landlord_id',

        /*
        |--------------------------------------------------------------------------
        | Sync Landlord With Tenant
        |--------------------------------------------------------------------------
        |
        | When set to true, resolving a tenant will automatically attempt to
        | resolve and activate its parent landlord as well. This keeps the
        | landlord context in sync with the tenant context without requiring
        | explicit landlord resolution middleware on every route.
        |
        */

        'sync_with_tenant' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Query Scoping
    |--------------------------------------------------------------------------
    |
    | These options configure the automatic query scoping behaviour applied to
    | Eloquent models that use the tenancy scoping traits. When scoping is
    | active, all queries against scoped models are automatically filtered by
    | the current tenant's identifier, preventing cross-tenant data leakage.
    |
    */

    'scoping' => [

        /*
        |--------------------------------------------------------------------------
        | Require Current Tenant for Scoped Queries
        |--------------------------------------------------------------------------
        |
        | When set to true, attempting to query a tenant-scoped model without an
        | active tenant context will throw an exception rather than returning an
        | unfiltered result set. This is a safety guard against accidentally
        | exposing data from all tenants on routes where resolution was skipped.
        |
        */

        'require_current_tenant' => false,

        /*
        |--------------------------------------------------------------------------
        | Tenant Foreign Key
        |--------------------------------------------------------------------------
        |
        | The name of the foreign key column used on scoped Eloquent models to
        | associate records with a tenant. This column must exist on every table
        | whose model uses the tenancy scoping traits. Defaults to "tenant_id".
        |
        */

        'tenant_foreign_key' => 'tenant_id',

    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Domain Lookup
    |--------------------------------------------------------------------------
    |
    | These options control how the tenancy system resolves tenants from the
    | incoming request hostname. This mirrors the landlord domain_lookup
    | configuration but applies to the tenant resolver pipeline.
    |
    */

    'domain_lookup' => [

        /*
        |--------------------------------------------------------------------------
        | Use Table for Tenant Domain Lookup
        |--------------------------------------------------------------------------
        |
        | When set to true, the system will query the tenant domains table to
        | resolve a tenant from the current request hostname. Set this to false
        | if you resolve tenants exclusively via subdomains or path segments that
        | do not require a dedicated domain table lookup.
        |
        */

        'use_table' => true,

        /*
        |--------------------------------------------------------------------------
        | Tenant Domain Lookup Cache
        |--------------------------------------------------------------------------
        |
        | To avoid a database query on every request, you may enable caching for
        | tenant domain lookups. When enabled, resolved tenant domain records are
        | stored in the cache for the configured number of seconds.
        |
        */

        'cache' => [

            /*
            |--------------------------------------------------------------------------
            | Enable Tenant Domain Cache
            |--------------------------------------------------------------------------
            |
            | Set to true to cache tenant domain lookup results. This is strongly
            | recommended in production environments where domain resolution occurs
            | on every request, as it eliminates a per-request database query.
            |
            */

            'enabled' => false,

            /*
            |--------------------------------------------------------------------------
            | Tenant Domain Cache TTL
            |--------------------------------------------------------------------------
            |
            | The number of seconds a cached tenant domain lookup result should be
            | retained before expiring. After expiry the next request will re-query
            | the database and refresh the cached entry.
            |
            */

            'ttl_seconds' => 60,

            /*
            |--------------------------------------------------------------------------
            | Tenant Domain Cache Store
            |--------------------------------------------------------------------------
            |
            | The name of the cache store that should be used to persist tenant domain
            | lookup results. When set to null, the default cache store defined in
            | config/cache.php will be used.
            |
            */

            'store' => null,

            /*
            |--------------------------------------------------------------------------
            | Tenant Domain Cache Prefix
            |--------------------------------------------------------------------------
            |
            | All cached tenant domain lookup entries will be stored under keys that
            | begin with this prefix. This helps avoid collisions with other cached
            | entries, including the landlord domain cache defined above.
            |
            */

            'prefix' => 'tenancy:domain:tenant:',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how the tenancy system responds to HTTP requests
    | when the tenancy pipeline encounters an error, such as an unresolvable
    | tenant or a domain not associated with any tenant.
    |
    */

    'http' => [

        /*
        |--------------------------------------------------------------------------
        | HTTP Abort Status Code
        |--------------------------------------------------------------------------
        |
        | The HTTP status code that will be returned when a request arrives for
        | a domain or path that cannot be matched to a known tenant. A 404 is
        | used by default to avoid leaking the existence of the tenancy system
        | to unauthenticated clients.
        |
        */

        'abort_status' => 404,

    ],

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | These options govern how the tenancy system stores and validates tenant
    | and landlord identity within the user's session. The system can store
    | the resolved identifiers in the session and verify them on subsequent
    | requests to guard against session tampering or fixation attacks.
    |
    */

    'session' => [

        /*
        |--------------------------------------------------------------------------
        | Tenant Session Scope Key
        |--------------------------------------------------------------------------
        |
        | The dot-notation key under which the resolved tenant's identifier is
        | stored in the session. This value is written when a tenant is resolved
        | and read on subsequent requests to perform coherence checks.
        |
        */

        'tenant_scope_key' => 'tenancy.tenant_id',

        /*
        |--------------------------------------------------------------------------
        | Landlord Session Scope Key
        |--------------------------------------------------------------------------
        |
        | The dot-notation key under which the resolved landlord's identifier is
        | stored in the session. Functions identically to tenant_scope_key but
        | targets the landlord identity within the session data.
        |
        */

        'landlord_scope_key' => 'tenancy.landlord_id',

        /*
        |--------------------------------------------------------------------------
        | Session Abort Status Code
        |--------------------------------------------------------------------------
        |
        | The HTTP status code returned when a session coherence check fails,
        | such as when the tenant identifier stored in the session does not match
        | the tenant resolved from the current request. A 403 is used by default
        | to signal an authorisation failure rather than a missing resource.
        |
        */

        'abort_status' => 403,

        /*
        |--------------------------------------------------------------------------
        | Invalidate Session on Mismatch
        |--------------------------------------------------------------------------
        |
        | When set to true, the tenancy system will invalidate and regenerate the
        | user's session whenever a coherence mismatch is detected between the
        | session-stored identity and the resolved tenant or landlord. This
        | provides an additional defence against session fixation attacks.
        |
        */

        'invalidate_on_mismatch' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Routing Configuration
    |--------------------------------------------------------------------------
    |
    | These options define the names of the route parameters that carry tenant
    | and landlord identifiers in URL-based routing strategies. Changing these
    | values will affect how the tenancy system binds model instances when
    | using implicit route model binding.
    |
    */

    'routing' => [

        /*
        |--------------------------------------------------------------------------
        | Tenant Route Parameter
        |--------------------------------------------------------------------------
        |
        | The name of the route parameter that contains the tenant's identifier.
        | This is used by the path-based resolver and by implicit route model
        | binding when a tenant instance is injected into a controller action.
        |
        */

        'tenant_parameter' => 'tenant',

        /*
        |--------------------------------------------------------------------------
        | Landlord Route Parameter
        |--------------------------------------------------------------------------
        |
        | The name of the route parameter that contains the landlord's identifier.
        | Functions identically to tenant_parameter but targets the landlord
        | model in path-based resolution and implicit route model binding.
        |
        */

        'landlord_parameter' => 'landlord',

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how the tenancy system propagates tenant context
    | to queued jobs. When a job is dispatched within an active tenant context,
    | the tenant's identifier is serialised into the job payload and the queue
    | name is prefixed so that worker processes can restore the correct tenant
    | before executing the job.
    |
    */

    'queue' => [

        /*
        |--------------------------------------------------------------------------
        | Tenant Queue Prefix
        |--------------------------------------------------------------------------
        |
        | This string is prepended to queue names when dispatching jobs within a
        | tenant context. It is combined with the delimiter and the tenant's
        | identifier to form a namespaced queue name that identifies the owning
        | tenant, e.g. "tenant:42:default".
        |
        */

        'prefix' => 'tenant',

        /*
        |--------------------------------------------------------------------------
        | Tenant Queue Delimiter
        |--------------------------------------------------------------------------
        |
        | The character used to join the prefix, tenant identifier, and the base
        | queue name when constructing the final namespaced queue name. Defaults
        | to a colon, following the conventional queue naming convention.
        |
        */

        'delimiter' => ':',

        /*
        |--------------------------------------------------------------------------
        | Default Queue Name
        |--------------------------------------------------------------------------
        |
        | The base queue name that will be used as the suffix of the namespaced
        | queue name when no explicit queue is specified on the dispatched job.
        | This corresponds to the queue name your worker processes are listening on.
        |
        */

        'default' => 'default',

        /*
        |--------------------------------------------------------------------------
        | Propagate Tenant Context to Jobs
        |--------------------------------------------------------------------------
        |
        | When set to true, the tenancy system will automatically inject the active
        | tenant's identifier into every job payload dispatched while a tenant is
        | active. The worker will use this payload to restore the tenant context
        | before the job's handle method is invoked.
        |
        */

        'propagate' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how the tenancy system behaves when running
    | scheduled commands across multiple tenants. The scheduler will iterate
    | over all tenants and execute any tenant-aware scheduled tasks within
    | each tenant's bootstrapped context.
    |
    */

    'scheduler' => [

        /*
        |--------------------------------------------------------------------------
        | Fail Fast
        |--------------------------------------------------------------------------
        |
        | When set to true, the tenancy scheduler will halt immediately if a
        | scheduled task throws an exception for any tenant, rather than continuing
        | to the next tenant. Set this to false to allow the scheduler to attempt
        | the task for all tenants even if one or more fail.
        |
        */

        'fail_fast' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Impersonation Configuration
    |--------------------------------------------------------------------------
    |
    | These options configure the tenant impersonation feature, which allows
    | privileged users such as administrators to temporarily act as a specific
    | tenant without logging in as that tenant's users. Impersonation tokens
    | are short-lived and stored in the cache.
    |
    */

    'impersonation' => [

        /*
        |--------------------------------------------------------------------------
        | Impersonation Token TTL
        |--------------------------------------------------------------------------
        |
        | The number of seconds an impersonation token remains valid after it is
        | issued. Once expired, the token can no longer be used to enter a tenant
        | context and a new token must be generated. Defaults to 300 seconds (five
        | minutes).
        |
        */

        'ttl_seconds' => 300,

        /*
        |--------------------------------------------------------------------------
        | Impersonation Cache Store
        |--------------------------------------------------------------------------
        |
        | The name of the cache store used to persist impersonation tokens. When
        | set to null, the default cache store defined in config/cache.php will be
        | used. For security-sensitive environments consider specifying a dedicated
        | store with a short maximum TTL.
        |
        */

        'cache_store' => null,

        /*
        |--------------------------------------------------------------------------
        | Impersonation Cache Prefix
        |--------------------------------------------------------------------------
        |
        | All impersonation token cache entries will be stored under keys that
        | begin with this prefix. Changing this value will effectively invalidate
        | all outstanding impersonation tokens, as the existing keys will no longer
        | be found in the cache.
        |
        */

        'cache_prefix' => 'tenancy:impersonation:tenant:',

        /*
        |--------------------------------------------------------------------------
        | Impersonation Query Parameter
        |--------------------------------------------------------------------------
        |
        | The name of the HTTP query parameter that carries the impersonation token
        | when a privileged user follows an impersonation link. The tenancy
        | middleware will inspect this parameter and, if a valid token is found,
        | will bootstrap the corresponding tenant context for the request.
        |
        */

        'query_parameter' => 'tenant_impersonation',

    ],

    /*
    |--------------------------------------------------------------------------
    | Additional Settings
    |--------------------------------------------------------------------------
    |
    | You may add any custom configuration values you need for your tenancy
    | implementation below. These values will be accessible throughout your
    | application via the config() helper.
    |
    */

];

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
// Here endeth thy configuration, noble developer!                            //
// Beyond: code so wretched, even wyrms learned the scribing arts.            //
// Forsooth, they but penned "// TODO: remedy ere long"                       //
// Three realms have fallen since...                                          //
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
//                                                  .~))>>                    //
//                                                 .~)>>                      //
//                                               .~))))>>>                    //
//                                             .~))>>             ___         //
//                                           .~))>>)))>>      .-~))>>         //
//                                         .~)))))>>       .-~))>>)>          //
//                                       .~)))>>))))>>  .-~)>>)>              //
//                   )                 .~))>>))))>>  .-~)))))>>)>             //
//                ( )@@*)             //)>))))))  .-~))))>>)>                 //
//              ).@(@@               //))>>))) .-~))>>)))))>>)>               //
//            (( @.@).              //))))) .-~)>>)))))>>)>                   //
//          ))  )@@*.@@ )          //)>))) //))))))>>))))>>)>                 //
//       ((  ((@@@.@@             |/))))) //)))))>>)))>>)>                    //
//      )) @@*. )@@ )   (\_(\-\b  |))>)) //)))>>)))))))>>)>                   //
//    (( @@@(.@(@ .    _/`-`  ~|b |>))) //)>>)))))))>>)>                      //
//     )* @@@ )@*     (@)  (@) /\b|))) //))))))>>))))>>                       //
//   (( @. )@( @ .   _/  /    /  \b)) //))>>)))))>>>_._                       //
//    )@@ (@@*)@@.  (6///6)- / ^  \b)//))))))>>))))>>   ~~-.                  //
// ( @jgs@@. @@@.*@_ VvvvvV//  ^  \b/)>>))))>>      _.     `bb                //
//  ((@@ @@@*.(@@ . - | o |' \ (  ^   \b)))>>        .'       b`,             //
//   ((@@).*@@ )@ )   \^^^/  ((   ^  ~)_        \  /           b `,           //
//     (@@. (@@ ).     `-'   (((   ^    `\ \ \ \ \|             b  `.         //
//       (*.@*              / ((((        \| | |  \       .       b `.        //
//                         / / (((((  \    \ /  _.-~\     Y,      b  ;        //
//                        / / / (((((( \    \.-~   _.`" _.-~`,    b  ;        //
//                       /   /   `(((((()    )    (((((~      `,  b  ;        //
//                     _/  _/      `"""/   /'                  ; b   ;        //
//                 _.-~_.-~           /  /'                _.'~bb _.'         //
//               ((((~~              / /'              _.'~bb.--~             //
//                                  ((((          __.-~bb.-~                  //
//                                              .'  b .~~                     //
//                                              :bb ,'                        //
//                                              ~~~~                          //
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
