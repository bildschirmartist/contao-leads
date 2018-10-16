<?php

declare(strict_types=1);

/*
 * leads Extension for Contao Open Source CMS
 *
 * @copyright  Copyright (c) 2011-2018, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 * @link       http://github.com/terminal42/contao-leads
 */

namespace Terminal42\LeadsBundle\Exporter;

use Haste\Http\Response\Response;
use Haste\IO\Reader\ArrayReader;
use Haste\IO\Writer\ExcelFileWriter;
use PHPExcel_Cell;
use PHPExcel_IOFactory;

abstract class AbstractExcelExporter extends AbstractExporter
{
    /**
     * Returns true if available.
     */
    public function isAvailable(): bool
    {
        return class_exists('PHPExcel') && class_exists('PHPExcel_IOFactory');
    }

    /**
     * Exports based on Excel format.
     *
     * @param \Database\Result|object $config
     * @param array|null              $ids
     * @param string                  $format
     *
     * @return \Contao\File
     */
    protected function exportWithFormat($config, $ids, $format)
    {
        $dataCollector = $this->prepareDefaultDataCollector($config, $ids);

        $reader = new ArrayReader($dataCollector->getExportData());

        if ($config->headerFields) {
            $reader->setHeaderFields($this->prepareDefaultHeaderFields($config, $dataCollector));
        }

        $columnConfig = $this->prepareDefaultExportConfig($config, $dataCollector);

        if ($config->useTemplate) {
            return $this->exportWithTemplate($config, $columnConfig, $reader, $format);
        }

        return $this->exportWithoutTemplate($config, $columnConfig, $reader, $format);
    }

    /**
     * Default export without template.
     *
     * @param $config
     * @param $format
     *
     * @throws ExportFailedException
     *
     * @return \Contao\File
     */
    protected function exportWithoutTemplate(
        $config,
        array $columnConfig,
        ArrayReader $reader,
        $format
    ) {
        $writer = new ExcelFileWriter('system/tmp/'.$this->exportFile->getFilenameForConfig($config));
        $writer->setFormat($format);

        // Add header fields
        if ($config->headerFields) {
            $writer->enableHeaderFields();
        }

        $writer->setRowCallback(function ($data) use ($config, $columnConfig) {
            return $this->dataTransformer->compileRow($data, $config, $columnConfig);
        });

        $this->handleDefaultExportResult($writer->writeFrom($reader));

        $this->updateLastRun($config);

        return new \Contao\File($writer->getFilename());
    }

    /**
     * Export with template.
     *
     * @param $config
     * @param $format
     *
     * @return \Contao\File
     */
    protected function exportWithTemplate(
        $config,
        array $columnConfig,
        ArrayReader $reader,
        $format
    ) {
        // Fetch the template and make a copy of it
        $template = \FilesModel::findByPk($config->template);

        if (null === $template) {
            $objResponse = new Response('Could not find template.', 500);
            $objResponse->send();
        }

        $tmpPath = 'system/tmp/'.$this->exportFile->getFilenameForConfig($config);
        \Files::getInstance()->copy($template->path, $tmpPath);

        $excelReader = PHPExcel_IOFactory::createReader($format);
        $excel = $excelReader->load(TL_ROOT.'/'.$tmpPath);

        $excel->setActiveSheetIndex((int) $config->sheetIndex);
        $sheet = $excel->getActiveSheet();

        $currentRow = (int) $config->startIndex ?: 1;
        $currentColumn = 0;

        foreach ($reader as $readerRow) {
            $compiledRow = $this->dataTransformer->compileRow($readerRow, $config, $columnConfig);

            foreach ($compiledRow as $k => $value) {
                // Support explicit target column
                if ('tokens' === $config->export && isset($config->tokenFields[$k]['targetColumn'])) {
                    $column = $config->tokenFields[$k]['targetColumn'];

                    if (!is_numeric($column)) {
                        $column = PHPExcel_Cell::columnIndexFromString($column) - 1;
                    }
                } else {
                    // Use next column, ignoring explicit target columns in the counter
                    $column = $currentColumn++;
                }

                $sheet->setCellValueExplicitByColumnAndRow(
                    $column,
                    $currentRow,
                    (string) $value,
                    \PHPExcel_Cell_DataType::TYPE_STRING2
                );
            }

            $currentColumn = 0;
            ++$currentRow;
        }

        $excelWriter = \PHPExcel_IOFactory::createWriter($excel, $format);
        $excelWriter->save(TL_ROOT.'/'.$tmpPath);

        $this->updateLastRun($config);

        return new \Contao\File($tmpPath);
    }
}