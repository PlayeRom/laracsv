<?php

namespace Playerom\Laracsv\Tests\Laracsv;

use Carbon\Carbon;
use League\Csv\Writer;
use Playerom\Laracsv\Export;
use Playerom\Laracsv\Tests\Laracsv\Models\Category;
use Playerom\Laracsv\Tests\Laracsv\Models\EnumType;
use Playerom\Laracsv\Tests\Laracsv\Models\Product;
use Playerom\Laracsv\Tests\TestCase;
use stdClass;

class ExportTest extends TestCase
{
    public function testBasicCsv(): void
    {
        $products = Product::limit(10)->get();

        $fields = ['id', 'title', 'price', 'original_price',];

        $csvExporter = new Export();
        $csvExporter->build($products, $fields);
        $csv = $csvExporter->getReader();
        $lines = explode(PHP_EOL, trim($csv->toString()));
        $firstLine = $lines[0];
        $this->assertEquals("id,title,price,original_price", $firstLine);
        $this->assertCount(11, $lines);
        $this->assertCount(count($fields), explode(',', $lines[2]));
    }

    public function testWithCustomHeaders(): void
    {
        $products = Product::limit(5)->get();

        $fields = ['id', 'title' => 'Name', 'price', 'original_price' => 'Retail Price', 'custom_field' => 'Custom Field'];

        $csvExporter = new Export();
        $csvExporter->build($products, $fields);
        $csv = $csvExporter->getReader();
        $lines = explode(PHP_EOL, trim($csv->toString()));
        $firstLine = $lines[0];
        $this->assertSame('id,Name,price,"Retail Price","Custom Field"', $firstLine);
    }

    public function testWithBeforeEachCallback(): void
    {
        $products = Product::limit(5)->get();

        $fields = ['id', 'title' => 'Name', 'price', 'original_price' => 'Retail Price', 'custom_field' => 'Custom Field'];

        $csvExporter = new Export();
        $csvExporter->beforeEach(function ($model) {
            if ($model->id == 2) {
                return false;
            }
            $model->custom_field = 'Test Value';
            $model->price = 30;
        });

        $csvExporter->build($products, $fields);

        $csv = $csvExporter->getReader();
        $lines = explode(PHP_EOL, trim($csv->toString()));
        $firstLine = $lines[0];
        $thirdRow = explode(',', $lines[2]);
        $this->assertSame('id,Name,price,"Retail Price","Custom Field"', $firstLine);
        $this->assertEquals(30, $thirdRow[2]);
        $this->assertSame('"Test Value"', $thirdRow[4]);
        $this->assertCount(5, $lines);
    }

    public function testBeforeEachChunkCallback(): void
    {
        $export = new Export();

        // Verify that the categories are not loaded
        $export->beforeEach(function ($record) {
            $this->assertFalse($record->relationLoaded('categories'));
        });

        $export->buildFromBuilder(Product::select(), ['id']);

        // Eager load the categories per chunk
        $export->beforeEachChunk(function ($collection) {
            $collection->load('categories');
        });

        // Verify that the categories are eagerly loaded per chunk
        $export->beforeEach(function ($record) {
            $this->assertTrue($record->relationLoaded('categories'));
        });

        $export->buildFromBuilder(Product::select(), ['id']);
    }

    public function testBuilderChunkSize(): void
    {
        $export = new Export();

        $export->beforeEachChunk(function ($collection) {
            $this->assertSame(10, $collection->count());
        });

        $export->buildFromBuilder(Product::select(), ['formatted_property' => 'Property']);

        $export->beforeEachChunk(function ($collection) {
            $this->assertSame(5, $collection->count());
        });

        $export->buildFromBuilder(Product::select(), ['formatted_property' => 'Property'], ['chunk' => 5]);
    }

    public function testBuilderHeader(): void
    {
        $export = new Export();

        $export->buildFromBuilder(Product::select(), ['id' => 'ID Header', 'price']);

        $lines = explode(PHP_EOL, trim($export->getReader()->toString()));

        $this->assertSame('"ID Header",price', $lines[0]);

        $export = new Export();

        $export->buildFromBuilder(Product::select(), ['id' => 'ID Header', 'price'], ['header' => false]);

        $lines = explode(PHP_EOL, trim($export->getReader()->toString()));

        $this->assertNotSame('"ID Header",price', $lines[0]);
    }

    public function testBuilder(): void
    {
        $export = new Export();

        $export->beforeEach(function ($record) {
            $record->formatted_property = 'id_' . $record->id;
        });

        $export->buildFromBuilder(Product::select(), ['formatted_property'], ['header' => false]);

        $lines = explode(PHP_EOL, trim($export->getReader()->toString()));

        foreach ($lines as $index => $line) {
            $this->assertSame('id_' . ($index + 1), $line);
        }
    }

    public function testUtf8(): void
    {
        foreach (range(11, 15) as $item) {
            $product = Product::create([
                'title' => 'رجا ابو سلامة',
                'price' => 70,
                'original_price' => 80,
            ]);

            $product->categories()->attach(Category::find(collect(range(1, 10))->random()));
        }

        $products = Product::where('title', 'رجا ابو سلامة')->get();
        $this->assertEquals('رجا ابو سلامة', $products->first()->title);

        $csvExporter = new Export();

        $csvExporter->build($products, ['title', 'price']);

        $csv = $csvExporter->getReader();
        $lines = explode(PHP_EOL, trim($csv->toString()));

        $this->assertSame('"رجا ابو سلامة",70', $lines[2]);
    }

    public function testCustomLeagueCsvWriters(): void
    {
        $products = Product::limit(10)->get();

        $fields = ['id', 'title', 'price', 'original_price',];
        file_put_contents('test.csv', '');
        $csvExporter = new Export(Writer::createFromPath('test.csv', 'r+'));
        $csvExporter->build($products, $fields);
        $csv = $csvExporter->getReader();

        $lines = explode(PHP_EOL, trim($csv->toString()));
        $firstLine = $lines[0];
        $this->assertEquals("id,title,price,original_price", $firstLine);
        $this->assertCount(11, $lines);
        $this->assertCount(count($fields), explode(',', $lines[2]));
        unlink('test.csv');
    }

    public function testCaseSensitiveRelationNames(): void
    {
        $cntCategories = 5;
        $categories = Category::limit($cntCategories)->with('mainCategory')->get();

        $csvExporter = new Export();

        $csvExporter->build($categories, [
            'id',
            'title',
            'main_category.id' => 'Parent Category ID',
        ]);

        $csv = $csvExporter->getReader();

        $secondLine = explode(',', explode(PHP_EOL, trim($csv->toString()))[1]);

        $this->assertCount(3, $secondLine); // There should be a parent id for each category
        $this->assertEquals(1, $secondLine[2]); // Parent ID is always seeded to #1
    }

    public function testIlluminateSupportCollection(): void
    {
        $faker = \Faker\Factory::create();

        $csvExporter = new Export();

        $data = [];
        for ($i = 1; $i < 5; $i++) {
            $data[] = [
                'id' => $i,
                'address' => $faker->streetAddress,
                'firstName' => $faker->firstName
            ];
        }
        $data = collect($data);
        $csvExporter->build($data, [
            'id',
            'firstName',
            'address'
        ]);

        $csv = $csvExporter->getWriter();
        $lines = explode(PHP_EOL, trim($csv));
        $this->assertCount(5, $lines);

        $fourthLine = explode(',', explode(PHP_EOL, trim($csv))[4]);

        $this->assertSame('4', $fourthLine[0]);
    }

    public function testExportPlainObjects(): void
    {
        $faker = \Faker\Factory::create();

        $csvExporter = new Export();

        $data = [];
        for ($i = 1; $i < 5; $i++) {
            $object = new stdClass();
            $object->id = $i;
            $object->address = $faker->streetAddress;
            $object->firstName = $faker->firstName;

            $data[] = $object;
        }

        $data = collect($data);

        $csvExporter->build($data, [
            'id',
            'firstName',
            'address'
        ]);

        $csv = trim($csvExporter->getWriter()->toString());

        $lines = explode(PHP_EOL, $csv);
        $fourthLine = explode(',', $lines[4]);

        $this->assertCount(5, $lines);
        $this->assertSame('4', $fourthLine[0]);
    }

    public function testRead(): void
    {
        $products = Product::limit(10)->get();

        $fields = ['id', 'title', 'price', 'original_price',];

        $csvExporter = new Export();
        $csvExporter->build($products, $fields);
        $reader = $csvExporter->getReader();
        $this->assertCount(11, $reader);
        $this->assertEquals('title', $reader->nth(0)[1]);
        $this->assertEquals(Product::first()->title, $reader->nth(1)[1]);
    }

    public function testJson(): void
    {
        $products = Product::limit(10)->get();

        $fields = ['id', 'title', 'price', 'original_price',];

        $csvExporter = new Export();
        $csvExporter->build($products, $fields);
        $reader = $csvExporter->getReader();
        $this->assertEquals(Product::first()->title, $reader->jsonSerialize()[1][1]);
    }

    public function testWriter(): void
    {
        $products = Product::limit(10)->get();

        $fields = ['id', 'title', 'price', 'original_price',];

        $csvExporter = new Export();
        $csvExporter->build($products, $fields);
        $writer = $csvExporter->getWriter();
        $this->assertNotFalse(strstr($writer->toString(), Product::first()->title));
    }

    public function testWithNoHeader(): void
    {
        $products = Product::limit(10)->get();

        $fields = ['id', 'title', 'price', 'original_price',];

        $csvExporter = new Export();
        $csvExporter->build($products, $fields, ['header' => false]);
        $csv = $csvExporter->getReader();
        $lines = explode(PHP_EOL, trim($csv->toString()));
        $firstLine = $lines[0];
        $this->assertNotEquals("id,title,price,original_price", $firstLine);
        $this->assertCount(10, $lines);
        $this->assertCount(count($fields), explode(',', $lines[2]));
    }

    public function testWithCustomDelimiter(): void
    {
        $products = Product::limit(10)->get();

        $fields = ['id', 'title', 'price', 'original_price',];

        $csvExporter = new Export();
        $csvExporter->getWriter()->setDelimiter(';');
        $csvExporter->build($products, $fields);
        $csv = $csvExporter->getReader();
        $lines = explode(PHP_EOL, trim($csv->toString()));
        $firstLine = $lines[0];
        $this->assertEquals("id;title;price;original_price", $firstLine);
        $this->assertCount(11, $lines);
        $this->assertCount(count($fields), explode(';', $lines[2]));
    }

    public function testSerializeCasting(): void
    {
        $cntCategories = 5;
        $products = Product::limit($cntCategories)->get();

        $csvExporter = new Export();

        $csvExporter->build($products, [
            'id',
            'production_date',
            'type',
        ]);

        $csv = $csvExporter->getReader();

        $secondLine = explode(',', explode(PHP_EOL, trim($csv->toString()))[1]);

        $this->assertEquals(Carbon::today()->toDateString(), $secondLine[1]); // Date as a sting in ISO format
        $this->assertEquals(EnumType::from($secondLine[2])->value, $secondLine[2]); // Type is sting, not enum
    }
}
