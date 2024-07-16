<?php
namespace Pescarolo\ImportExport\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\ProgressBar;
use Magento\Framework\App\State;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;

class ExportProducts extends Command
{
    const ARG_SKU = 'sku';

    protected $state;
    protected $resource;

    public function __construct(State $state, ResourceConnection $resource)
    {
        $this->state = $state;
        $this->resource = $resource;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('pescarolo:export-products')
            ->setDescription('Export products based on SKU and optional creation date')
            ->addArgument(self::ARG_SKU, InputArgument::REQUIRED, 'SKU Filter');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode('adminhtml');
        $skuFilter = $input->getArgument(self::ARG_SKU);

        // Solicitando a data de criação interativamente
        $helper = $this->getHelper('question');
        $question = new Question('Please enter the creation date filter (YYYY-MM-DD, leave empty for all products): ');
        $dateFilter = $helper->ask($input, $output, $question);

        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('catalog_product_entity');

        if (!empty($dateFilter)) {
            $sql = "SELECT * FROM $tableName WHERE sku LIKE :sku AND created_at >= :date";
            $bind = [':sku' => $skuFilter . '%', ':date' => $dateFilter];
        } else {
            $sql = "SELECT * FROM $tableName WHERE sku LIKE :sku";
            $bind = [':sku' => $skuFilter . '%'];
        }

        try {
            $results = $connection->fetchAll($sql, $bind);
            $filePath = BP . "/var/export/products_$skuFilter.sql";
            $sqlDump = "-- Magento Products Export\n\n";
            $totalRows = count($results);

            // Iniciando a ProgressBar
            $progressBar = new ProgressBar($output, $totalRows);
            $progressBar->start();

            foreach ($results as $row) {
                $sqlDump .= "INSERT INTO $tableName VALUES ("
                    . implode(',', array_map([$connection, 'quote'], $row)) . ");\n";
                $progressBar->advance();
            }

            file_put_contents($filePath, $sqlDump);
            exec("gzip $filePath");

            $progressBar->finish();
            $output->writeln(""); // Adiciona uma nova linha após a barra de progresso
            $output->writeln("<info>Products exported to $filePath.gz</info>");

            // Solicitar reindexação
            $output->writeln("<info>Reindexing the store content...</info>");
            $reindexOutput = [];
            exec("php bin/magento indexer:reindex", $reindexOutput);
            foreach ($reindexOutput as $line) {
                $output->writeln("<info>$line</info>");
            }

            $output->writeln("<info>Reindexing completed successfully.</info>");

            return Cli::RETURN_SUCCESS;
        } catch (LocalizedException $e) {
            $output->writeln("<error>Failed to export products: " . $e->getMessage() . "</error>");
            return Cli::RETURN_FAILURE;
        }
    }
}
