<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs\Stages;

use App\Product;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSourceFactory;
use stdClass;
use Tests\IntegrationTestCase;

final class PullFromSourceTest extends IntegrationTestCase
{
    public function test_put_all_entites_to_index(): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = rand(1, 5);

        factory(Product::class, $productsAmount)->create();

        Product::setEventDispatcher($dispatcher);
        $this->elasticsearch->indices()->create([
            'index' => 'products_index',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);
        PullFromSource::chunked(DefaultImportSourceFactory::from(Product::class))->each->handle();
        $this->elasticsearch->indices()->refresh([
            'index' => 'products',
        ]);
        $params = [
            'index' => 'products',
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);
        $this->assertEquals($productsAmount, $response['hits']['total']['value']);
    }

    public function test_dont_put_entities_if_no_entities_in_collection(): void
    {
        $this->elasticsearch->indices()->create([
            'index' => 'products_index',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);
        PullFromSource::chunked(DefaultImportSourceFactory::from(Product::class))->each->handle();
        $this->elasticsearch->indices()->refresh([
            'index' => 'products',
        ]);
        $params = [
            'index' => 'products',
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);
        $this->assertEquals(0, $response['hits']['total']['value']);
    }

    public function test_put_all_to_index_if_amount_of_entities_more_than_chunk_size(): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = 20;
        factory(Product::class, $productsAmount)->create();

        Product::setEventDispatcher($dispatcher);
        $this->elasticsearch->indices()->create([
            'index' => 'products_index',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);
        PullFromSource::chunked(DefaultImportSourceFactory::from(Product::class))->each->handle();
        $this->elasticsearch->indices()->refresh([
            'index' => 'products',
        ]);
        $params = [
            'index' => 'products',
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);
        $this->assertEquals($productsAmount, $response['hits']['total']['value']);
    }

    public function test_pull_soft_delete_meta_data()
    {
        $this->app['config']['scout.soft_delete'] = true;

        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = rand(1, 5);

        factory(Product::class, $productsAmount)->create();

        Product::setEventDispatcher($dispatcher);
        $this->elasticsearch->indices()->create([
            'index' => 'products_index',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);
        PullFromSource::chunked(DefaultImportSourceFactory::from(Product::class))->each->handle();
        $this->elasticsearch->indices()->refresh([
            'index' => 'products',
        ]);
        $params = [
            'index' => 'products',
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);
        $this->assertEquals(0, $response['hits']['hits'][0]['_source']['__soft_deleted']);
    }

    public function test_pull_soft_deleted()
    {
        $this->app['config']['scout.soft_delete'] = true;

        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = 3;

        factory(Product::class, $productsAmount)->create();

        Product::limit(1)->get()->first()->delete();

        Product::setEventDispatcher($dispatcher);
        $this->elasticsearch->indices()->create([
            'index' => 'products_index',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);
        PullFromSource::chunked(DefaultImportSourceFactory::from(Product::class))->each->handle();
        $this->elasticsearch->indices()->refresh([
            'index' => 'products',
        ]);
        $params = [
            'index' => 'products',
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);
        $this->assertEquals(3, $response['hits']['total']['value']);
    }

    public function test_no_searchables_no_chunks()
    {
        $count = PullFromSource::chunked(DefaultImportSourceFactory::from(Product::class))->count();
        $this->assertEquals(0, $count);
    }

    public function test_chunked_pull_only_one_page()
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = 5;

        factory(Product::class, $productsAmount)->create();

        Product::setEventDispatcher($dispatcher);

        $stages = PullFromSource::chunked(DefaultImportSourceFactory::from(Product::class));
        $stages->first()->handle();
        $this->elasticsearch->indices()->refresh([
            'index' => 'products',
        ]);
        $params = [
            'index' => 'products',
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);

        $this->assertEquals(3, $response['hits']['total']['value']);
    }
}
