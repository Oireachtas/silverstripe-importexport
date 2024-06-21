<?php

namespace ilateral\SilverStripe\ImportExport\GridField;

use SilverStripe\Forms\Form;
use SilverStripe\Assets\File;
use SilverStripe\Core\Convert;
use SilverStripe\View\ArrayData;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Cache\CacheFactory;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use ilateral\SilverStripe\ImportExport\CSVFieldMapper;

/**
 * Request handler that provides a seperate interface
 * for users to map columns and trigger import.
 */
class GridFieldImporter_Request extends RequestHandler
{

    /**
     * Gridfield instance
     * @var GridField
     */
    protected $gridField;

    /**
     * The parent GridFieldImporter
     * @var GridFieldImporter
     */
    protected $component;

    /**
     * URLSegment for this request handler
     * @var string
     */
    protected $urlSegment = 'importer';

    /**
     * Parent handler to link up to
     * @var RequestHandler
     */
    protected $requestHandler;

    /**
     * RequestHandler allowed actions
     * @var array
     */
    private static $allowed_actions = [
        'preview',
        'upload',
        'import'
    ];

    /**
     * RequestHandler url => action map
     * @var array
     */
    private static $url_handlers = [
        'upload!' => 'upload',
        '$Action/$FileID' => '$Action'
    ];

    /**
     * Handler's constructor
     *
     * @param GridField $gridField
     * @param GridField_URLHandler $component
     * @param RequestHandler $handler
     */
    public function __construct($gridField, $component, $handler)
    {
        $this->gridField = $gridField;
        $this->component = $component;
        $this->requestHandler = $handler;
        parent::__construct();
    }

    /**
     * Return the original component's UploadField
     *
     * @return UploadField UploadField instance as defined in the component
     */
    public function getUploadField()
    {
        return $this->component->getUploadField($this->gridField);
    }

    /**
     * Create a temporary file from a data stream and return
     * it's filepath
     * 
     * @param string $stream Data stream
     * 
     * @return string
     */
    protected function tempFileFromStream($stream)
    {
        // create a temporary file and stream the CSV file contents
        // into it.
        $file = tempnam(sys_get_temp_dir(), 'impexp');
        file_put_contents($file, $stream);

        return $file;
    }

    /**
     * Upload the given file, and import or start preview.
     * @param  SS_HTTPRequest $request
     * @return string
     */
    public function upload(HTTPRequest $request)
    {
        $field = $this->getUploadField();
        $uploadResponse = $field->upload($request);
        //decode response body. ugly hack ;o
        $body = json_decode($uploadResponse->getBody(), true);
        $body = array_shift($body);
        //add extra data
        $body['import_url'] = Controller::join_links(
            $this->Link('preview'), $body['id'],
            // Also pull the back URL from the current request so we can persist
            // this particular URL through the following pages.
            "?BackURL=" . $this->getBackURL($request)
        );
        //don't return buttons at all
        unset($body['buttons']);

        //re-encode
        $response = HTTPResponse::create(json_encode([$body]));

        return $response;
    }

    /**
     * Action for getting preview interface.
     * @param  SS_HTTPRequest $request
     * @return string
     */
    public function preview(HTTPRequest $request)
    {
        $file = File::get()
            ->byID($request->param('FileID'));
        if (!$file) {
            return "file not found";
        }

        $temp_file = $this->tempFileFromStream($file->getStream());

        //TODO: validate file?
        $mapper = new CSVFieldMapper($temp_file);
        $mapper->setMappableCols($this->getMappableColumns());

        //load previously stored values
        if ($cachedmapping = $this->getCachedMapping()) {
            $mapper->loadDataFrom($cachedmapping);
        }

        $form = $this->MapperForm();
        $form->Fields()->unshift(
            new LiteralField('mapperfield', $mapper->forTemplate())
        );
        $form->Fields()->push(new HiddenField("BackURL", "BackURL", $this->getBackURL($request)));
        $form->setFormAction($this->Link('import').'/'.$file->ID);

        $content = ArrayData::create(array(
            'File' => $file,
            'MapperForm'=> $form
        ))->renderWith(GridFieldImporter::class . '_preview');
        $controller = $this->getToplevelController();

        return $controller->customise(array(
            'Content' => $content
        ));
    }

    /**
     * The import form for creating mapping,
     * and choosing options.
     * @return Form
     */
    public function MapperForm()
    {
        $fields = new FieldList(
            CheckboxField::create(
                "HasHeader",
                "This data includes a header row.",
                true
            )
        );
        if ($this->component->getCanClearData()) {
            $fields->push(
                CheckboxField::create(
                    "ClearData",
                    "Remove all existing records before import."
                )
            );
        }
        $actions = FieldList::create(
            FormAction::create("import", "Import CSV")
                ->setUseButtonTag(true)
                ->addExtraClass("btn btn-primary btn--icon-large font-icon-upload"),
            FormAction::create("cancel", "Cancel")
                ->setUseButtonTag(true)
                ->addExtraClass("btn btn-outline-danger btn-hide-outline font-icon-cancel-circled")
        );

        $form = new Form($this, __FUNCTION__, $fields, $actions);

        return $form;
    }

    /**
     * Get all columns that can be mapped to in BulkLoader
     * @return array
     */
    protected function getMappableColumns()
    {
        return $this->component->getLoader($this->gridField)
                    ->getMappableColumns();
    }

    /**
     * Import the current file
     * @param  SS_HTTPRequest $request
     */
    public function import(HTTPRequest $request)
    {
        $hasheader = (bool)$request->postVar('HasHeader');
        $cleardata = $this->component->getCanClearData() ?
                         (bool)$request->postVar('ClearData') :
                         false;
        if ($request->postVar('action_import')) {
            $file = File::get()
                ->byID($request->param('FileID'));
            if (!$file) {
                return "file not found";
            }

            $temp_file = $this->tempFileFromStream($file->getStream());

            $colmap = Convert::raw2sql($request->postVar('mappings'));
            if ($colmap) {
                //save mapping to cache
                $this->cacheMapping($colmap);
                //do import
                $results = $this->importFile(
                    $temp_file,
                    $colmap,
                    $hasheader,
                    $cleardata
                );
                $this->gridField->getForm()
                    ->sessionMessage($results->getMessage(), 'good');
            }
        }
        $controller = $this->getToplevelController();
        $controller->redirectBack();
    }

    /**
     * Do the import using the configured importer.
     * @param  string $filepath
     * @param  array|null $colmap
     * @return BulkLoader_Result
     */
    public function importFile($filepath, $colmap = null, $hasheader = true, $cleardata = false)
    {
        $loader = $this->component->getLoader($this->gridField);
        $loader->deleteExistingRecords = $cleardata;

        //set or merge in given col map
        if (is_array($colmap)) {
            $loader->columnMap = $loader->columnMap ?
                array_merge($loader->columnMap, $colmap) : $colmap;
        }
        $loader->getSource()
            ->setFilePath($filepath)
            ->setHasHeader($hasheader);

        return $loader->load();
    }

    /**
     * Pass fileexists request to UploadField
     *
     * @link UploadField->fileexists()
     */
    public function fileexists(HTTPRequest $request)
    {
        $uploadField = $this->getUploadField();
        return $uploadField->fileexists($request);
    }

    /**
     * @param string $action
     * @return string
     */
    public function Link($action = null)
    {
        return Controller::join_links(
            $this->gridField->Link(),
            $this->urlSegment,
            $action
        );
    }

    /**
     * @see GridFieldDetailForm_ItemRequest::getTopLevelController
     * @return Controller
     */
    protected function getToplevelController()
    {
        $c = $this->requestHandler;
        while ($c && $c instanceof GridFieldDetailForm_ItemRequest) {
            $c = $c->getController();
        }
        if (!$c) {
            $c = Controller::curr();
        }

        return $c;
    }

    /**
     * Store the user defined mapping for future use.
     */
    protected function cacheMapping($mapping)
    {
        $mapping = array_filter($mapping);
        if ($mapping && !empty($mapping)) {
            $cache = Injector::inst()->get(CacheInterface::class .'.gridfieldimporter');
            $cache->set($this->cacheKey(), serialize($mapping));
        }
    }

    /**
     * Look for a previously stored user defined mapping.
     */
    protected function getCachedMapping()
    {
        $cache = Injector::inst()->get(CacheInterface::class .'.gridfieldimporter');
        if ($result = $cache->get($this->cacheKey())) {
            return unserialize($result);
        }
    }

    /**
     * Generate a cache key unique to this gridfield
     */
    protected function cacheKey()
    {
        return md5($this->gridField->Link());
    }

    /**
     * Get's the previous URL that lead up to the current request.
     *
     * NOTE: Honestly, this should be built into SS_HTTPRequest, but we can't depend on that right now... so instead,
     * this is being copied verbatim from Controller (in the framework).
     *
     * @param SS_HTTPRequest $request
     * @return string
     */
    public function getBackURL()
    {
        $request = $this->getRequest();
        if (!$request) {
            return null;
        }

        // Initialize a sane default (basically redirects to root admin URL).
        $controller = $this->getToplevelController();

        if (method_exists($this->requestHandler, "Link")) {
            $url = $this->requestHandler->Link();
        } else {
            $url = $controller->Link();
        }

        // Try to parse out a back URL using standard framework technique.
        if ($request->requestVar('BackURL')) {
            $url = $request->requestVar('BackURL');
        } elseif ($request->isAjax() && $request->getHeader('X-Backurl')) {
            $url = $request->getHeader('X-Backurl');
        } elseif ($request->getHeader('Referer')) {
            $url = $request->getHeader('Referer');
        }

        return $url;
    }
}
