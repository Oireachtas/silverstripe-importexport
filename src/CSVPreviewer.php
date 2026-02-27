<?php

namespace ilateral\SilverStripe\ImportExport;

use SilverStripe\Dev\CSVParser;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\Model\ModelData;

/**
 * View the content of a given CSV file
 */
class CSVPreviewer extends ModelData
{

    protected $file;

    protected $headings;

    protected $rows;

    protected $previewcount = 5;

    public function __construct($file)
    {
        $this->file = $file;
    }

    /**
     * Choose the nubmer of lines to preview
     */
    public function setPreviewCount($count)
    {
        $this->previewcount = $count;

        return $this;
    }

    /**
     * Extract preview of CSV from file
     */
    public function loadCSV()
    {
        $parser = new CSVParser($this->file);
        $count = 0;
        foreach ($parser as $row) {
            $this->rows[]= $row;
            $count++;
            if ($count == $this->previewcount) {
                break;
            }
        }
        $firstrow = array_keys($this->rows[0]);

        //hack to include first row as a
        array_unshift($this->rows, array_combine($firstrow, $firstrow));

        if (count($this->rows) > 0) {
            $this->headings = $firstrow;
        }
    }

    /**
     * Render the previewer
     * @return string
     */
    public function forTemplate(): string
    {
        if (!$this->rows) {
            $this->loadCSV();
        }
        return $this->renderWith(self::class);
    }

    /**
     * Get the CSV headings for use in template
     * @return ArrayList
     */
    public function getHeadings()
    {
        if (!$this->headings) {
            return;
        }
        $out = new ArrayList();
        foreach ($this->headings as $heading) {
            $out->push(
                new ArrayData(array(
                    "Label" => $heading
                ))
            );
        }
        return $out;
    }

    /**
     * Get CSV rows/cols for use in template
     * @return ArrayList
     */
    public function getRows()
    {
        $out = ArrayList::create();
        foreach ($this->rows as $row) {
            $columns = new ArrayList();
            foreach ($row as $column => $value) {
                $columns->push(
                    ArrayData::create(
                        [
                            "Heading"=> $column,
                            "Value" => $value
                        ]
                    )
                );
            }
            $out->push(
                new ArrayData(array(
                    "Columns" => $columns
                ))
            );
        }
        return $out;
    }
}
