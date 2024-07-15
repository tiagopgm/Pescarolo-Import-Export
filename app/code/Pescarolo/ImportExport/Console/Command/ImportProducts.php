<?php
namespace Pescarolo\ImportExport\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\State;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;

class ImportProducts extends Command
{
    const ARG_FILE = 'file';

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
        $this->setName('pescarolo:import-products')
            ->setDescription('Import products from a SQL file located in var/import/')
            ->addArgument(self::ARG_FILE, InputArgument::REQUIRED, 'SQL.gz File Name in var/import/');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode('adminhtml');
        $fileName = $input->getArgument(self::ARG_FILE);
        $filePath = BP . "/var/import/" . $fileName;

        if (!file_exists($filePath)) {
            $output->writeln("<error>File $filePath does not exist</error>");
            return Cli::RETURN_FAILURE;
        }

        try {
            $uncompressedFilePath = str_replace('.gz', '', $filePath);
            $output->writeln("<info>Uncompressing the file...</info>");
            exec("gunzip -c $filePath > $uncompressedFilePath");

            $connection = $this->resource->getConnection();
            $output->writeln("<info>Importing products...</info>");
            $sql = file_get_contents($uncompressedFilePath);
            $connection->query($sql);

            $output->writeln("<info>Products imported successfully from $fileName</info>");

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
            $output->writeln("<error>Failed to import products: " . $e->getMessage() . "</error>");
            return Cli::RETURN_FAILURE;
        }
    }
}
