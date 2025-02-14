<?php

namespace Playerom\Laracsv;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;

class Export
{
    /**
     * The default chunk size when looping through the builder results.
     */
    private const int DEFAULT_CHUNK_SIZE = 1000;

    /**
     * The applied callback.
     *
     * @var callable|null
     */
    protected $beforeEachCallback;

    /**
     * The callback applied before handling each chunk.
     *
     * @var callable|null
     */
    protected $beforeEachChunkCallback;

    /**
     * The CSV writer.
     */
    protected Writer $writer;

    /**
     * Export configuration.
     */
    protected array $config = [];

    /**
     * Export constructor.
     */
    public function __construct(?Writer $writer = null)
    {
        $this->writer = $writer ?: Writer::createFromFileObject(new SplTempFileObject);
    }

    /**
     * Build the writer.
     *
     * @throws \League\Csv\CannotInsertRecord
     */
    public function build(Collection $collection, array $fields, array $config = []): self
    {
        $this->config = $config;

        $this->addHeader($this->writer, $this->getHeaderFields($fields));
        $this->addCsvRows($this->writer, $this->getDataFields($fields), $collection);

        return $this;
    }

    /**
     * Build the CSV from a builder instance.
     *
     * @throws \League\Csv\CannotInsertRecord
     */
    public function buildFromBuilder(Builder $builder, array $fields, array $config = []): self
    {
        $this->config = $config;

        $chunkSize = Arr::get($config, 'chunk', self::DEFAULT_CHUNK_SIZE);
        $dataFields = $this->getDataFields($fields);

        $this->addHeader($this->writer, $this->getHeaderFields($fields));

        $builder->chunk($chunkSize, function ($collection) use ($dataFields) {
            $callback = $this->beforeEachChunkCallback;

            if ($callback && $callback($collection) === false) {
                return;
            }

            $this->addCsvRows($this->writer, $dataFields, $collection);
        });

        return $this;
    }

    /**
     * Download the CSV file.
     */
    public function download(?string $filename = null): void
    {
        $filename = $filename ?: date('Y-m-d_His') . '.csv';

        $this->writer->download($filename);
    }

    /**
     * Set the callback.
     */
    public function beforeEach(callable $callback): self
    {
        $this->beforeEachCallback = $callback;

        return $this;
    }

    /**
     * Callback which is run before processing each chunk.
     */
    public function beforeEachChunk(callable $callback): self
    {
        $this->beforeEachChunkCallback = $callback;

        return $this;
    }

    /**
     * Get a CSV reader.
     */
    public function getReader(): Reader
    {
        return Reader::createFromString($this->writer->toString());
    }

    /**
     * Get the CSV writer.
     */
    public function getWriter(): Writer
    {
        return $this->writer;
    }

    /**
     * Get all the data fields for the current set of fields.
     */
    private function getDataFields(array $fields): array
    {
        foreach ($fields as $key => $field) {
            if (is_string($key)) {
                $fields[$key] = $key;
            }
        }

        return array_values($fields);
    }

    /**
     * Get all the header fields for the current set of fields.
     */
    private function getHeaderFields(array $fields): array
    {
        return array_values($fields);
    }

    /**
     * Add rows to the CSV.
     *
     * @throws \League\Csv\CannotInsertRecord
     */
    private function addCsvRows(Writer $writer, array $fields, Collection $collection): void
    {
        foreach ($collection as $model) {
            $beforeEachCallback = $this->beforeEachCallback;

            // Call hook
            if ($beforeEachCallback) {
                $return = $beforeEachCallback($model);

                if ($return === false) {
                    continue;
                }
            }

            if (!Arr::accessible($model)) {
                $model = collect($model);
            }

            // If $model is a Model object them serialize it to an array,
            // so that Enums and Carbons will correctly convert to strings.
            $arrayModel = $model instanceof Model
                ? $model->toArray()
                : $model;

            $csvRow = [];
            foreach ($fields as $field) {
                $csvRow[] = Arr::get($arrayModel, $field);
            }

            $writer->insertOne($csvRow);
        }
    }

    /**
     * Adds a header row to the CSV.
     */
    private function addHeader(Writer $writer, array $headers): void
    {
        if (Arr::get($this->config, 'header', true) !== false) {
            $writer->insertOne($headers);
        }
    }
}
