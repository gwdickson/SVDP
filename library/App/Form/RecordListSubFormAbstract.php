<?php

abstract class App_Form_RecordListSubFormAbstract extends Zend_Form_SubForm
{

    private $_safeSerializeService;

    private $_namespace;

    private $_labels;

    private $_readOnly;

    private $_legendMsg;

    private $_descriptionMsg;

    private $_addRecordMsg;

    private $_noRecordsMsg;

    private $_removedRecordsField;

    private $_removedRecordsHashField;

    private $_recordsSubForm;

    private $_addRecordBtn;

    private $_submitBtn;

    public function __construct($options)
    {
        // Initialize necessary services.
        $this->_safeSerializeService = new App_Service_SafeSerialize();

        // Pass options on to the standard sub form class.
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        }

        if (!is_array($options)) {
            throw new DomainException('Options must be an array or a config object.');
        }

        parent::__construct(array(
            'decorators' => array(
                array('ViewScript', array(
                    'viewScript' => 'form/record-list-sub-form-abstract.phtml',
                    'labels' => &$this->_labels,
                    'readOnly' => &$this->_readOnly,
                    'legendMsg' => &$this->_legendMsg,
                    'descriptionMsg' => &$this->_descriptionMsg,
                    'noRecordsMsg' => &$this->_noRecordsMsg,
                    'removedRecordsField' => &$this->_removedRecordsField,
                    'removedRecordsHashField' => &$this->_removedRecordsHashField,
                    'recordsSubForm' => &$this->_recordsSubForm,
                    'addRecordBtn' => &$this->_addRecordBtn,
                    'submitBtn' => &$this->_submitBtn,
                )),
            ),
            'elementDecorators' => array('ViewHelper'),
        ) + $options);

        // Save options for the record sub forms.
        if (!isset($options['namespace'])) {
            throw new InvalidArgumentException('Record list sub form namespace must be provided');
        }
        if (!isset($options['labels'])) {
            throw new InvalidArgumentException('Labels for record sub forms must be provided.');
        }

        $this->_namespace = $options['namespace'];
        $this->_labels    = $options['labels'];

        $this->_readOnly = (isset($options['readOnly'])) ? $options['readOnly'] : false;

        // Get custom message strings.
        $this->_legendMsg      = isset($options['legend']) ? $options['legend'] : 'Records:';
        $this->_descriptionMsg = isset($options['description']) ? $options['description'] : null;
        $this->_addRecordMsg   = isset($options['addRecordMsg'])
            ? $options['addRecordMsg']
            : 'Add Another Record';
        $this->_noRecordsMsg   = isset($options['noRecordsMsg'])
            ? $options['noRecordsMsg']
            : 'No records listed.';

        // Create hidden elements to hold removed records across POST requests.
        if (!$this->_readOnly) {
            $safeSerializedEmptyArray = $this->_safeSerializeService->serialize(array());

            $this->addElement('hidden', "{$this->_namespace}RecordsRemoved", array(
                'value' => $safeSerializedEmptyArray['serial'],
            ));

            $this->addElement('hidden', "{$this->_namespace}RecordsRemovedHash", array(
                'value' => $safeSerializedEmptyArray['hash'],
            ));

            $this->_removedRecordsField = $this->getElement("{$this->_namespace}RecordsRemoved");
            $this->_removedRecordsHashField
                = $this->getElement("{$this->_namespace}RecordsRemovedHash");
        }

        // Create records sub form to hold the record sub forms. (It's sub forms all the way down!)
        $this->_recordsSubForm = new Zend_Form_SubForm(array(
            'decorators' => array('FormElements'),
            'elementDecorators' => array('ViewHelper'),
        ));

        $this->addSubForm($this->_recordsSubForm, "{$this->_namespace}Records");

        // Create add record button.
        if (!$this->_readOnly) {
            $this->addElement('submit', "{$this->_namespace}RecordAdd", array(
                'label' => $this->_addRecordMsg,
                'class' => 'btn btn-info',
            ));
        }

        $this->_addRecordBtn = $this->getElement("{$this->_namespace}RecordAdd");

        if (isset($options['submitMsg']) && !$this->_readOnly) {
            // If necessary, create submit changes button.
            $this->addElement('submit', "{$this->_namespace}Submit", array(
                'label' => $options['submitMsg'],
                'class' => 'btn btn-success',
            ));

            $this->_submitBtn = $this->getElement("{$this->_namespace}Submit");
        }
    }

    public function preValidate($data)
    {
        if (!$this->_readOnly && isset($data["{$this->_namespace}Records"])) {
            foreach ($data["{$this->_namespace}Records"] as $recordName => $recordData) {
                $this->_recordsSubForm->addSubForm($this->addEmptyRecord(), $recordName);
            }
        }
    }

    public function handleAddRemoveRecords($data)
    {
        if ($this->_readOnly) {
            return;
        }

        if (isset($data["{$this->_namespace}RecordAdd"])) {
            $subFormKeys = array_keys($this->_recordsSubForm->getSubForms());

            $this->_recordsSubForm->addSubForm(
                $this->addEmptyRecord(),
                $subFormKeys ? max($subFormKeys) + 1 : 0
            );

            return true;
        } else if (isset($data["{$this->_namespace}Records"])) {
            foreach ($data["{$this->_namespace}Records"] as $recordName => $recordData) {
                if (isset($recordData['remove'])) {
                    $recordSubForm = $this->_recordsSubForm->getSubForm($recordName);
                    $record        = $this->getRecord($recordSubForm);

                    $this->_recordsSubForm->removeSubForm($recordName);

                    if ($record->getId() !== null) {
                        $removedRecords = array_merge($this->getRemovedRecords(), array($record));
                        $safeSerializedRemovedRecords
                            = $this->_safeSerializeService->serialize($removedRecords);
                        $this->_removedRecordsField->setValue(
                            $safeSerializedRemovedRecords['serial']
                        );
                        $this->_removedRecordsHashField->setValue(
                            $safeSerializedRemovedRecords['hash']
                        );
                    }

                    return true;
                }
            }
        }

        return false;
    }

    public function getChangedRecords()
    {
        $changedRecords = array();

        if (!$this->_readOnly) {
            foreach ($this->_recordsSubForm->getSubForms() as $recordName => $recordSubForm) {
                $changedRecords[$recordName] = $this->getRecord($recordSubForm);
            }
        }

        return $changedRecords;
    }

    public function getRemovedRecords()
    {
        return !$this->_readOnly
            ? $this->_safeSerializeService->unserialize(
                $this->_removedRecordsField->getValue(),
                $this->_removedRecordsHashField->getValue()
            )
            : array();
    }

    public function setRecords($records)
    {
        $this->_recordsSubForm->clearSubForms();

        $i = 0;

        foreach ($records as $record)
        {
            $recordSubForm = $this->addEmptyRecord();
            $this->setRecord($recordSubForm, $record);
            $this->_recordsSubForm->addSubForm($recordSubForm, $i++);
        }
    }

    private function addEmptyRecord()
    {
        $recordSubForm = new Zend_Form_SubForm();
        $recordSubForm
            ->addElementPrefixPath(
                'Twitter_Bootstrap_Form_Decorator',
                'Twitter/Bootstrap/Form/Decorator',
                'decorator'
            )
            ->setDecorators(array(
                'FormElements',
                array('HtmlTag', array('tag' => 'tr')),
            ))
            ->setElementDecorators(array(
                'ViewHelper',
                'ElementErrors',
                'Wrapper',
                array('HtmlTag', array('tag' => 'td')),
            ));

        $this->initSubForm($recordSubForm);

        if ($this->_readOnly) {
            foreach ($recordSubForm->getElements() as $element) {
                if ($element instanceof Zend_Form_Element_Select) {
                    $element->setAttrib('disabled', true);
                } else {
                    $element->setAttrib('readonly', true);
                }
            }
        } else {
            $recordSubForm->addElement('submit', 'remove', array(
                'label' => '×',
                'decorators' => array(
                    'ViewHelper',
                    array('HtmlTag', array('tag' => 'td', 'class' => 'remove')),
                ),
                'class' => 'btn btn-danger remove',
            ));
        }

        return $recordSubForm;
    }

    protected abstract function initSubForm($recordSubForm);

    protected abstract function getRecord($recordSubForm);

    protected abstract function setRecord($recordSubForm, $record);
}
