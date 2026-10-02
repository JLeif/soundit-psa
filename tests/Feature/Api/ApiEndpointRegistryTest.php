<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\VerifyApiToken;
use App\Support\ApiEndpointRegistry;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The route table and ApiEndpointRegistry are a bijection: every route behind
 * VerifyApiToken resolves to a registry entry, and every registry entry (and
 * every alias) has exactly one route with its method and path. Forgetting
 * either half turns this red.
 */
class ApiEndpointRegistryTest extends TestCase
{
    /** @return array<int, RoutingRoute> */
    private function gatedRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $r): bool => in_array(VerifyApiToken::class, $r->gatherMiddleware(), true),
        ));
    }

    private function verb(RoutingRoute $r): string
    {
        return collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();
    }

    public function test_every_gated_route_resolves_to_a_registry_entry(): void
    {
        $routes = $this->gatedRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $endpoint = ApiEndpointRegistry::endpointForRouteName($route->getName());
            $this->assertNotNull($endpoint, 'route '.$route->uri().' ('.$route->getName().') has no registry entry');

            $entry = ApiEndpointRegistry::all()[$endpoint];
            $this->assertSame($entry['method'], $this->verb($route), $route->uri());
            $expected = array_map(fn ($p) => 'api/'.$p, array_merge([$entry['path']], $entry['aliases']));
            $this->assertContains($route->uri(), $expected, $route->uri());
        }
    }

    public function test_every_registry_entry_and_alias_has_exactly_one_route(): void
    {
        $byUri = [];
        foreach ($this->gatedRoutes() as $route) {
            $byUri[$this->verb($route).' '.$route->uri()][] = $route->getName();
        }

        $expected = [];
        foreach (ApiEndpointRegistry::all() as $name => $entry) {
            $expected[$entry['method'].' api/'.$entry['path']] = [ApiEndpointRegistry::CANONICAL_ROUTE_PREFIX.$name];
            $this->assertNotEmpty($entry['aliases'], "{$name}: every v1 endpoint keeps its /api/rmm alias until the RMM moves");
            foreach ($entry['aliases'] as $alias) {
                $expected[$entry['method'].' api/'.$alias] = [ApiEndpointRegistry::ALIAS_ROUTE_PREFIX.$name];
            }
        }

        ksort($byUri);
        ksort($expected);
        $this->assertSame($expected, $byUri);
    }

    public function test_canonical_paths_are_v1_and_aliases_are_the_rmm_contract(): void
    {
        // Ruling 1 (card w5kVPHVZ): /api/v1/* canonical; /api/rmm/* alias on
        // the same handler and grant. The alias paths are the ones the LITS
        // RMM client hard-codes.
        $aliases = [];
        foreach (ApiEndpointRegistry::all() as $entry) {
            $this->assertStringStartsWith('v1/', $entry['path']);
            foreach ($entry['aliases'] as $alias) {
                $aliases[] = $entry['method'].' '.$alias;
            }
        }
        sort($aliases);

        $this->assertSame([
            'GET rmm/assets',
            'GET rmm/clients',
            'POST rmm/alerts',
            'POST rmm/alerts/resolve',
        ], $aliases);
    }

    public function test_alias_and_canonical_routes_share_the_handler(): void
    {
        foreach (ApiEndpointRegistry::names() as $name) {
            $v1 = Route::getRoutes()->getByName(ApiEndpointRegistry::CANONICAL_ROUTE_PREFIX.$name);
            $alias = Route::getRoutes()->getByName(ApiEndpointRegistry::ALIAS_ROUTE_PREFIX.$name);
            $this->assertNotNull($v1, $name);
            $this->assertNotNull($alias, $name);
            $this->assertSame($v1->getActionName(), $alias->getActionName(), $name);
            $this->assertSame($v1->gatherMiddleware(), $alias->gatherMiddleware(), $name);
        }
    }

    public function test_every_entry_names_a_known_group_and_access(): void
    {
        $groups = ApiEndpointRegistry::groups();
        foreach (ApiEndpointRegistry::all() as $name => $entry) {
            $this->assertArrayHasKey($entry['group'], $groups, $name);
            $this->assertContains($entry['access'], ['read', 'write'], $name);
            $this->assertSame($entry['access'] === 'read' ? 'GET' : 'POST', $entry['method'], $name);
            $this->assertNotSame('', trim($entry['description']), $name);
        }
    }

    public function test_route_names_outside_the_family_resolve_to_nothing(): void
    {
        $this->assertNull(ApiEndpointRegistry::endpointForRouteName(null));
        $this->assertNull(ApiEndpointRegistry::endpointForRouteName('clients.read'));
        $this->assertNull(ApiEndpointRegistry::endpointForRouteName('api.v1.nope'));
        $this->assertNull(ApiEndpointRegistry::endpointForRouteName('api.v2.clients.read'));
        $this->assertSame('clients.read', ApiEndpointRegistry::endpointForRouteName('api.rmm.clients.read'));
    }
}
