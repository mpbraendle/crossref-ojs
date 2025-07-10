<?php

/**
 * @file plugins/generic/crossref/filter/PostedContentCrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2000-2025 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class PostedContentCrossrefXmlFilter
 *
 * @ingroup plugins_generic_crossref
 *
 * @brief Class that converts a submission of type posted_content to a Crossref XML document.
 */

namespace APP\plugins\generic\crossref\filter;

use APP\author\Author;
use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\i18n\LocaleConversion;
use PKP\submission\GenreDAO;

class PostedContentCrossrefXmlFilter extends IssueCrossrefXmlFilter
{
    /**
     * Constructor
     *
     * @param \PKP\filter\FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        parent::__construct($filterGroup);
        $this->setDisplayName('Crossref XML posted content export');
        // uncomment for debugging
        // $this->_noValidation = true;
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @see \PKP\filter\Filter::process()
     *
     * @param array $pubObjects Array of submissions
     *
     * @return \DOMDocument
     */
    public function &process(&$pubObjects)
    {
        // Create the XML document
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);

        // Create and append the 'head' node and all parts inside it
        $rootNode->appendChild($this->createHeadNode($doc));

        // Create and append the 'body' node, that contains everything
        $bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');
        $rootNode->appendChild($bodyNode);

        foreach ($pubObjects as $pubObject) {
            // pubObject is submission
            $postedContentNode = $this->createPostedContentNode($doc, $pubObject);
            $bodyNode->appendChild($postedContentNode);
        }
        return $doc;
    }

    /**
     * Create and return the node 'posted_content'.
     *
     * @param \DOMDocument $doc
     * @param \APP\submission\Submission $submission
     *
     * @return \DOMElement
     */
    public function createPostedContentNode($doc, $submission)
    {
        /** @var CrossrefExportDeployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();

        $publication = $submission->getCurrentPublication();
        $locale = $publication->getData('locale');

        $issueId = $publication->getData('issueId');
        $issue = Repo::issue()->get($issueId);
        $sectionId = $publication->getData('sectionId');
        $section = Repo::section()->get($sectionId);
        $resourceType = $section->getData('resourceType');
        $postedContentType = $this->_mapCoarToPostedContentType($resourceType);

        $postedContentNode = $doc->createElementNS($deployment->getNamespace(), 'posted_content');
        $postedContentNode->setAttribute('type', $postedContentType);
        $postedContentNode->setAttribute('language', LocaleConversion::getIso1FromLocale($locale));

        // group title - create from journal and issue title
         $journalTitle = $context->getName($context->getPrimaryLocale());
        // Attempt a fall back, in case the localized name is not set.
        if ($journalTitle == '') {
            $journalTitle = $context->getData('abbreviation', $context->getPrimaryLocale());
        }
        $groupTitle = $journalTitle;
        if ($issue->getVolume() && $issue->getShowVolume()) {
            $groupTitle .= ', Vol. ' . $issue->getVolume();
        }
        if ($issue->getNumber() && $issue->getShowNumber()) {
            $groupTitle .= ', No. ' . $issue->getNumber();
        }
        $groupTitleNode = $doc->createElementNS($deployment->getNamespace(), 'group_title',  htmlspecialchars($groupTitle, ENT_COMPAT, 'UTF-8') );
        $postedContentNode->appendChild($groupTitleNode);

        // contributors
        $authors = $publication->getData('authors');
        if ($authors->count() != 0) {
            $contributorsNode = $doc->createElementNS($deployment->getNamespace(), 'contributors');

            $isFirst = true;
            foreach ($authors as $author) { /** @var Author $author */
                $personNameNode = $doc->createElementNS($deployment->getNamespace(), 'person_name');
                $personNameNode->setAttribute('contributor_role', 'author');

                if ($isFirst) {
                    $personNameNode->setAttribute('sequence', 'first');
                } else {
                    $personNameNode->setAttribute('sequence', 'additional');
                }

                $familyNames = $author->getFamilyName(null);
                $givenNames = $author->getGivenName(null);

                // Check if both givenName and familyName is set for the submission language.
                if (!empty($familyNames[$locale]) && !empty($givenNames[$locale])) {
                    $personNameNode->setAttribute('language', LocaleConversion::getIso1FromLocale($locale));
                    $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars(ucfirst($givenNames[$locale]), ENT_COMPAT, 'UTF-8')));
                    $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($familyNames[$locale]), ENT_COMPAT, 'UTF-8')));

                    if ($author->getData('orcid')) {
                        $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $author->getData('orcid')));
                    }

                    $hasAltName = false;
                    foreach ($familyNames as $otherLocal => $familyName) {
                        if ($otherLocal != $locale && isset($familyName) && !empty($familyName)) {
                            if (!$hasAltName) {
                                $altNameNode = $doc->createElementNS($deployment->getNamespace(), 'alt-name');
                                $personNameNode->appendChild($altNameNode);
                                $hasAltName = true;
                            }

                            $nameNode = $doc->createElementNS($deployment->getNamespace(), 'name');
                            $nameNode->setAttribute('language', LocaleConversion::getIso1FromLocale($otherLocal));

                            $nameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($familyName), ENT_COMPAT, 'UTF-8')));
                            if (isset($givenNames[$otherLocal]) && !empty($givenNames[$otherLocal])) {
                                $nameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars(ucfirst($givenNames[$otherLocal]), ENT_COMPAT, 'UTF-8')));
                            }

                            $altNameNode->appendChild($nameNode);
                        }
                    }
                } else {
                    $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($givenNames[$locale]), ENT_COMPAT, 'UTF-8')));
                    if ($author->getData('orcid')) {
                        $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $author->getData('orcid')));
                    }
                }

                $contributorsNode->appendChild($personNameNode);
                $isFirst = false;
            }
            $postedContentNode->appendChild($contributorsNode);
        }

        // titles
        // for posted content, only 1 titles element is allowed
        $titleLanguages = array_keys($publication->getTitles());
        $primaryLanguageIndex = array_search($locale, $titleLanguages);
        if ($primaryLanguageIndex) {
            unset($titleLanguages[$primaryLanguageIndex]);
            array_unshift($titleLanguages, $locale);
        }
        $languageCounter = 1;
        foreach ($titleLanguages as $lang) {
            $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
            $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title'));
            $node->appendChild($doc->createTextNode($publication->getLocalizedTitle($lang, 'html')));
            if ($subtitle = $publication->getLocalizedSubTitle($lang, 'html')) {
                $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subtitle'));
                $node->appendChild($doc->createTextNode($subtitle));
            }
            $postedContentNode->appendChild($titlesNode);
            $languageCounter++;
            if ($languageCounter > 1) {
                break;
            }
        }

        // posted date
        if ($datePublished = $publication->getData('datePublished')) {
            $postedContentNode->appendChild($this->createDateNode($doc, $datePublished, 'posted_date'));
        }

        // acceptance date
        $editorDecision = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany()
            ->first(fn (Decision $decision, $key) => $decision->getData('decision') === Decision::ACCEPT);

        if ($editorDecision) {
            $postedContentNode->appendChild($this->createDateNode($doc,  $editorDecision->getData('dateDecided'), 'accepted_date'));
        }

        // abstract
        $abstracts = $publication->getData('abstract') ?: [];
        foreach($abstracts as $lang => $abstract) {
            $abstractNode = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:abstract');
            $abstractNode->setAttributeNS($deployment->getXMLNamespace(), 'xml:lang', LocaleConversion::getIso1FromLocale($lang));
            $abstractNode->appendChild($node = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:p', htmlspecialchars(html_entity_decode(strip_tags($abstract), ENT_COMPAT, 'UTF-8'), ENT_COMPAT, 'UTF-8')));
            $postedContentNode->appendChild($abstractNode);
        }

        // fundref data - ToDo

        // access indicators (license)
        if ($publication->getData('licenseUrl')) {
            $licenseNode = $doc->createElementNS($deployment->getAINamespace(), 'ai:program');
            $licenseNode->setAttribute('name', 'AccessIndicators');
            $licenseNode->appendChild($node = $doc->createElementNS($deployment->getAINamespace(), 'ai:license_ref', htmlspecialchars($publication->getData('licenseUrl'), ENT_COMPAT, 'UTF-8')));
            $postedContentNode->appendChild($licenseNode);
        }

        // DOI data
        $dispatcher = $this->_getDispatcher($request);
        $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'view', $submission->getBestId(), null, null, true);
        $doiDataNode = $this->createDOIDataNode($doc, $publication->getDoi(), $url);
        // append galleys files and collection nodes to the DOI data node
        $galleys = $publication->getData('galleys');
        // All full-texts, PDF full-texts and remote galleys for text-mining and as-crawled URL
        $submissionGalleys = $pdfGalleys = $remoteGalleys = [];
        // preferred PDF full-text for the as-crawled URL
        $pdfGalleyInArticleLocale = null;
        // get immediately also supplementary files for component list
        $componentGalleys = [];
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        foreach ($galleys as $galley) {
            // filter supp files with DOI
            if (!$galley->getRemoteURL()) {
                $galleyFile = $galley->getFile();
                if ($galleyFile) {
                    $genre = $genreDao->getById($galleyFile->getGenreId());
                    if ($genre->getSupplementary()) {
                        if ($galley->getDoi()) {
                            // construct the array key with galley best ID and locale needed for the component node
                            $componentGalleys[] = $galley;
                        }
                    } else {
                        $submissionGalleys[] = $galley;
                        if ($galley->isPdfGalley()) {
                            $pdfGalleys[] = $galley;
                            if (!$pdfGalleyInArticleLocale && $galley->getLocale() == $locale) {
                                $pdfGalleyInArticleLocale = $galley;
                            }
                        }
                    }
                }
            } else {
                $remoteGalleys[] = $galley;
            }
        }
        // as-crawled URLs
        $asCrawledGalleys = [];
        if ($pdfGalleyInArticleLocale) {
            $asCrawledGalleys = [$pdfGalleyInArticleLocale];
        } elseif (!empty($pdfGalleys)) {
            $asCrawledGalleys = [$pdfGalleys[0]];
        } else {
            $asCrawledGalleys = $submissionGalleys;
        }
        // as-crawled URL - collection nodes
        $this->appendAsCrawledCollectionNodes($doc, $doiDataNode, $submission, $asCrawledGalleys);
        // text-mining - collection nodes
        $submissionGalleys = array_merge($submissionGalleys, $remoteGalleys);
        $this->appendTextMiningCollectionNodes($doc, $doiDataNode, $submission, $submissionGalleys);
        $postedContentNode->appendChild($doiDataNode);

        // component list (supplementary files)
        if (!empty($componentGalleys)) {
            $postedContentNode->appendChild($this->createComponentListNode($doc, $submission, $componentGalleys));
        }

        // citation_list - ToDo

        return $postedContentNode;
    }

    /**
     * Append the collection node 'collection property="crawler-based"' to the doi data node.
     * This function may be moved to a centralized utility class
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $doiDataNode
     * @param \APP\submission\Submission $submission
     * @param array $galleys of \PKP\galley\Galley objects
     */
    public function appendAsCrawledCollectionNodes($doc, $doiDataNode, $submission, $galleys)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);

        if (empty($galleys)) {
            $crawlerBasedCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'collection');
            $crawlerBasedCollectionNode->setAttribute('property', 'crawler-based');
            $doiDataNode->appendChild($crawlerBasedCollectionNode);
        }
        foreach ($galleys as $galley) {
            $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$submission->getBestId(), $galley->getBestGalleyId()], null, null, true);
            // iParadigms crawler based collection element
            $crawlerBasedCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'collection');
            $crawlerBasedCollectionNode->setAttribute('property', 'crawler-based');
            $iParadigmsItemNode = $doc->createElementNS($deployment->getNamespace(), 'item');
            $iParadigmsItemNode->setAttribute('crawler', 'iParadigms');
            $iParadigmsItemNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'resource', $resourceURL));
            $crawlerBasedCollectionNode->appendChild($iParadigmsItemNode);
            $doiDataNode->appendChild($crawlerBasedCollectionNode);
        }
    }

    /**
     * Append the collection node 'collection property="text-mining"' to the doi data node.
     * This function may be moved to a centralized utility class
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $doiDataNode
     * @param \APP\submission\Submission $submission
     * @param array $galleys of \PKP\galley\Galley objects
     */
    public function appendTextMiningCollectionNodes($doc, $doiDataNode, $submission, $galleys)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);

        // start of the text-mining collection element
        $textMiningCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'collection');
        $textMiningCollectionNode->setAttribute('property', 'text-mining');
        foreach ($galleys as $galley) {
            $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$submission->getBestId(), $galley->getBestGalleyId()], null, null, true); // text-mining collection item
            $textMiningItemNode = $doc->createElementNS($deployment->getNamespace(), 'item');
            $resourceNode = $doc->createElementNS($deployment->getNamespace(), 'resource', $resourceURL);
            if (!$galley->getRemoteURL()) {
                $resourceNode->setAttribute('mime_type', $galley->getFileType());
            }
            $textMiningItemNode->appendChild($resourceNode);
            $textMiningCollectionNode->appendChild($textMiningItemNode);
        }
        $doiDataNode->appendChild($textMiningCollectionNode);
    }

    /**
     * Create and return component list node 'component_list'.
     * This function may be moved to a centralized utility class
     *
     * @param \DOMDocument $doc
     * @param \APP\submission\Submission $submission
     * @param array $componentGalleys
     *
     * @return \DOMElement
     */
    public function createComponentListNode($doc, $submission, $componentGalleys)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);

        // Create the base node
        $componentListNode = $doc->createElementNS($deployment->getNamespace(), 'component_list');
        // Run through supp files and add component nodes.
        foreach ($componentGalleys as $componentGalley) {
            $componentFile = $componentGalley->getFile();
            $componentNode = $doc->createElementNS($deployment->getNamespace(), 'component');
            $componentNode->setAttribute('parent_relation', 'isPartOf');
            /* Titles */
            $componentFileTitle = $componentFile->getData('name', $componentGalley->getLocale());
            if (!empty($componentFileTitle)) {
                $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
                $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title', htmlspecialchars($componentFileTitle, ENT_COMPAT, 'UTF-8')));
                $componentNode->appendChild($titlesNode);
            }
            // DOI data node
            $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$submission->getBestId(), $componentGalley->getBestGalleyId()], null, null, true);
            $componentNode->appendChild($this->createDOIDataNode($doc, $componentGalley->getStoredPubId('doi'), $resourceURL));
            $componentListNode->appendChild($componentNode);
        }
        return $componentListNode;
    }

    private function _mapCoarToPostedContentType($uri = null)
    {
        $mapResourceTypes = array(
            'http://purl.org/coar/resource_type/c_26e4' => 'other',
            'http://purl.org/coar/resource_type/c_8544' => 'other',
            'http://purl.org/coar/resource_type/c_6670' => 'other',
            'http://purl.org/coar/resource_type/c_c94f' => 'other',
            'http://purl.org/coar/resource_type/c_816b' => 'other',
            'http://purl.org/coar/resource_type/F8RT-TJK0' => 'other',
            'http://purl.org/coar/resource_type/YC9F-HGCF' => 'other',
            'http://purl.org/coar/resource_type/c_c513' => 'other',
            'http://purl.org/coar/resource_type/c_8a7e' => 'other',
            'http://purl.org/coar/resource_type/c_18cc' => 'other',
            'http://purl.org/coar/resource_type/c_18cd' => 'other'
        );
        if ($uri && array_key_exists($uri, $mapResourceTypes)) {
            return $mapResourceTypes[$uri];
        } else {
            return 'preprint';
        }
    }

    /**
     * Create and return a date node (may be 'posted_date' or 'acceptance_date')
     * This function may be moved to centralized utility class
     *
     * @param \DOMDocument $doc
     * @param string $date
     * @param string $element_name 
     *
     * @return \DOMElement
     */

    public function createDateNode($doc, $date, $element_name)
    {
        $deployment = $this->getDeployment();
        $dateConv = strtotime($date);
        $dateNode = $doc->createElementNS($deployment->getNamespace(), $element_name);
        if (date('m', $dateConv)) {
            $dateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'month', date('m', $dateConv)));
        }
        if (date('d', $dateConv)) {
            $dateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'day', date('d', $dateConv)));
        }
        $dateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'year', date('Y', $dateConv)));
        return $dateNode;
    }
}
