<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

class qtPackageExtractorMETSArchivematicaDIP extends qtPackageExtractorBase
{
  protected function processDmdSec($xml, $informationObject)
  {
    $creation = array();

    $xml->registerXPathNamespace("m", "http://www.loc.gov/METS/");

    $dublincore = $xml->xpath('.//m:mdWrap/m:xmlData/*');
    $dublincore = end($dublincore);
    $dublincore->registerXPathNamespace("dc", "http://purl.org/dc/terms/");

    foreach ($dublincore as $item)
    {
      $value = trim($item->__toString());
      if (0 == strlen($value))
      {
        continue;
      }

      switch (str_replace('dcterms:', '', $item->getName()))
      {
        case 'title':
          $informationObject->setTitle($value);

          break;

        case 'creator':
          $creation['actorName'] = $value;
          break;

        case 'provenance':
          $informationObject->acquisition = $value;

          break;

        case 'coverage':
          $informationObject->setAccessPointByName($value, array('type_id' => QubitTaxonomy::PLACE_ID));

          break;

        case 'subject':
          $informationObject->setAccessPointByName($value, array('type_id' => QubitTaxonomy::SUBJECT_ID));

          break;

        case 'description':
          $informationObject->scopeAndContent = $value;

          break;

        case 'publisher':
          $informationObject->setActorByName($value, array('event_type_id' => QubitTerm::PUBLICATION_ID));

          break;

        case 'contributor':
          $informationObject->setActorByName($value, array('event_type_id' => QubitTerm::CONTRIBUTION_ID));

          break;

        case 'date':
          $creation['date'] = $value;

          break;

        case 'type':
          foreach (QubitTaxonomy::getTermsById(QubitTaxonomy::DC_TYPE_ID) as $item)
          {
            if (strtolower($value) == strtolower($item->__toString()))
            {
              $relation = new QubitObjectTermRelation;
              $relation->term = $item;

              $informationObject->objectTermRelationsRelatedByobjectId[] = $relation;

              break;
            }
          }

          break;

        case 'extent':
        case 'format':
          $informationObject->extentAndMedium = $value;

          break;

        case 'identifier':
          $informationObject->identifier = $value;

          break;

        case 'source':
          $informationObject->locationOfOriginals = $value;

          break;

        case 'language':
          // TODO: the user could write "English" instead of "en"? (see symfony...widget/i18n/*)
          $informationObject->language = array($value);

          break;

        case 'isPartOf':
          // TODO: ?

          break;

        case 'rights':
          $informationObject->accessConditions = $value;

          break;
      }
    }

    return array($informationObject, $creation);
  }

  protected function addCreationEvent($informationObject, $options)
  {
    $event = new QubitEvent;
    $event->informationObjectId = $informationObject->id;
    $event->typeId = QubitTerm::CREATION_ID;

    if ($options['actorName'])
    {
      $actor = QubitFlatfileImport::createOrFetchActor($options['actorName']);
      $event->actorId = $actor->id;
    }

    if ($options['date'])
    {
      $date = $options['date'];

      // normalize expression of date range
      $date = str_replace('/', '|', $date);
      $date = str_replace(' - ', '|', $date);

      if (substr_count($date, '|'))
      {
        // date is a range
        $dates = explode('|', $date);

        // if date is a range, set start/end dates
        if (count($dates) == 2)
        {
          $parsedDates = array();

          // parse each component date
          foreach($dates as $dateItem)
          {
            array_push($parsedDates, QubitFlatfileImport::parseDate($dateItem));
          }

          $event->startDate = $parsedDates[0];
          $event->endDate   = $parsedDates[1];

          // if date range is similar to ISO 8601 then make it a normal date range
          if ($this->likeISO8601Date(trim($dates[0])))
          {
            if ($event->startDate == $event->endDate)
            {
              $date = $event->startDate;
            } else {
              $date = $event->startDate .'|'. $event->endDate;
            }
          }
        }

        // if date is a single ISO 8601 date then truncate off time
        if ($this->likeISO8601Date(trim($event->date)))
        {
          $date = substr(trim($event->date), 0, 10);
        }

        // make date range indicator friendly
        $event->date = str_replace('|', ' - ', $date);
      } else {
        // date isn't a range
        $event->date = QubitFlatfileImport::parseDate($date);
      }
    }

    $event->save();
  }

  protected function likeISO8601Date($date)
  {
    $date = substr($date, 0, 19) .'Z';

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/', $date, $parts) == true) { 
      $time = gmmktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);

      $input_time = strtotime($date);
      if ($input_time === false) return false;

      return $input_time == $time;
    } else {
      return false;
    }
  }

  protected function process()
  {
    // Find METS file
    if ($handle = opendir($this->filename))
    {
      while (false !== $entry = readdir($handle))
      {
        if (0 < preg_match('/^METS\..*\.xml$/', $entry))
        {
          $this->document = new SimpleXMLElement(@file_get_contents($this->filename.DIRECTORY_SEPARATOR.$entry));

          break;
        }
      }

      closedir($handle);
    }
    else
    {
      throw new sfException('METS XML file was not found.');
    }

    if (!isset($this->document))
    {
      throw new sfException('METS document could not be opened.');
    }

    $this->document->registerXPathNamespace('m', 'http://www.loc.gov/METS/');

    // AIP UUID
    $aipUUID = $this->getUUID($this->filename);

    $publicationStatus = sfConfig::get('app_defaultPubStatus', QubitTerm::PUBLICATION_STATUS_DRAFT_ID);

    $this->createParent = true;
    // Main object
    if ($this->createParent && null != ($dmdSec = $this->getMainDmdSec()))
    {
      $parent = new QubitInformationObject;
      list($parent, $creation) = $this->processDmdSec($dmdSec, $parent);
      $parent->setLevelOfDescriptionByName('file');

      $this->resource->informationObjectsRelatedByparentId[] = $parent;
      $parent->save();

      if (count($creation)) {
        $this->addCreationEvent($parent, $creation);
      }
    }

    $mapping = $this->getStructMapFileToDmdSecMapping();

    foreach ($this->getFilesFromDirectory($this->filename.DIRECTORY_SEPARATOR.'/objects') as $item)
    {
      $parts = pathinfo($item);
      $filename = $parts['basename'];

      // Object UUID
      $objectUUID = $this->getUUID($filename);

      // Create child
      $child = new QubitInformationObject;
      $child->setPublicationStatus($publicationStatus);
      $child->setLevelOfDescriptionByName('item');

      // Get title from filename, remove UUID (36 + hyphen)
      $child->title = substr($filename, 37);

      // Process metatadata from METS file
      if (null !== ($dmdSec = $this->searchFileDmdSec($objectUUID, $mapping)))
      {
        list($child, $creation) = $this->processDmdSec($dmdSec, $child);
        $child->save();

        if (count($creation))
        {
          $this->addCreationEvent($child, $creation);
        }
      }

      // Storage UUIDs
      $child->addProperty('objectUUID', $objectUUID);
      $child->addProperty('aipUUID', $aipUUID);

      // Add digital object
      $digitalObject = new QubitDigitalObject;
      $digitalObject->assets[] = new QubitAsset($item);
      $digitalObject->usageId = QubitTerm::MASTER_ID;
      $child->digitalObjects[] = $digitalObject;

      if (isset($parent))
      {
        $parent->informationObjectsRelatedByparentId[] = $child;
      } else {
        $this->resource->informationObjectsRelatedByparentId[] = $child;
      }
    }

    $this->resource->save();

    parent::process();
  }

  protected function getStructMapFileToDmdSecMapping()
  {
    $mapping = array();

    $items = $this->document->xpath('//m:structMap[@LABEL="Hierarchical arrangement"]/m:div/m:div');

    foreach($items as $item)
    {
      $attributes = $item->attributes();
      $dmdId = (string)$attributes['DMDID'];

      if ($dmdId)
      {
        $fptrAttributes = $item->fptr->attributes();
        $fileId = (string)$fptrAttributes['FILEID'];

        if ($fileId)
        {
          $mapping[$fileId] = $dmdId;
        }
      }
    }

    return $mapping;
  }

  protected function getMainDmdSec()
  {
    $items = $this->document->xpath('//m:structMap[@LABEL="Hierarchical arrangement"]/m:div');

    $id = $items[0]['DMDID'];

    $dmdSec = $this->document->xpath('//m:dmdSec[@ID="'.$id.'"]');
    if (0 < count($dmdSec))
    {
      return $dmdSec[0];
    }
  }

  protected function searchFileDmdSec($uuid, $mapping)
  {
    $node = $this->document->xpath('//m:mets/m:fileSec/m:fileGrp[@USE="original"]');

    if (empty($node))
    {
      return;
    }

    foreach ($node[0] as $item)
    {
      if (false !== strstr($item['ID'], $uuid))
      {
        $id = (string)$item['ID'];

        if (isset($mapping[$id]))
        {
          $dmdId = $mapping[$id];

          $dmdSec = $this->document->xpath('//m:mets/m:dmdSec[@ID="'.$dmdId.'"]');
          if (0 < count($dmdSec))
          {
            return $dmdSec[0];
          }
        }
      }
    }
  }
}
