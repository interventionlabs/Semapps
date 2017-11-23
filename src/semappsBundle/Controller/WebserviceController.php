<?php

namespace semappsBundle\Controller;

use VirtualAssembly\SparqlBundle\Services\SparqlClient;
use semappsBundle\semappsConfig;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use VirtualAssembly\SemanticFormsBundle\Services\SemanticFormsClient;

class WebserviceController extends Controller
{
		const TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    var $entitiesTabs = [
      semappsConfig::URI_PAIR_ORGANIZATION => [
        'name'   => 'Organisation',
        'plural' => 'Organisations',
        'icon'   => 'tower',
				'nameType' => 'organization'
      ],
      semappsConfig::URI_PAIR_PERSON       => [
        'name'   => 'Personne',
        'plural' => 'Personnes',
        'icon'   => 'user',
				'nameType' => 'person'
      ],
      semappsConfig::URI_PAIR_PROJECT      => [
        'name'   => 'Projet',
        'plural' => 'Projets',
        'icon'   => 'screenshot',
				'nameType' => 'projet'
      ],
      semappsConfig::URI_PAIR_EVENT        => [
        'name'   => 'Event',
        'plural' => 'Events',
        'icon'   => 'calendar',
				'nameType' => 'event'
      ],
      semappsConfig::URI_PAIR_PROPOSAL  => [
        'name'   => 'Proposition',
        'plural' => 'Propositions',
        'icon'   => 'info-sign',
				'nameType' => 'proposition'
      ],
			semappsConfig::URI_PAIR_DOCUMENT  => [
				'name'   => 'Document',
				'plural' => 'Documents',
				'icon'   => 'folder-open',
				'nameType' => 'document'
			],
			semappsConfig::URI_PAIR_DOCUMENT_TYPE  => [
				'name'   => 'Type de document',
				'plural' => 'Types de document',
				'icon'   => 'pushpin',
				'nameType' => 'documenttype'
			],

    ];

    var $entitiesFilters = [
      semappsConfig::URI_PAIR_ORGANIZATION,
      semappsConfig::URI_PAIR_PERSON,
      semappsConfig::URI_PAIR_PROJECT,
      semappsConfig::URI_PAIR_EVENT,
      semappsConfig::URI_PAIR_PROPOSAL,
      semappsConfig::URI_SKOS_THESAURUS,
      semappsConfig::URI_PAIR_DOCUMENT,
      semappsConfig::URI_PAIR_DOCUMENT_TYPE,

    ];

    public function __construct()
    {
        // We also need to type as property.
        foreach ($this->entitiesTabs as $key => $item) {
            $this->entitiesTabs[$key]['type'] = $key;
        }
    }

    public function parametersAction()
    {
        $cache = new FilesystemAdapter();
        $parameters = $cache->getItem('gv.webservice.parameters');

        //if (!$parameters->isHit()) {
            $user = $this->GetUser();

            // Get results.
            $results = $this->searchSparqlRequest(
              '',
              semappsConfig::URI_SKOS_THESAURUS
            );

            $thesaurus = [];
            foreach ($results as $item) {
                $thesaurus[] = [
                  'uri'   => $item['uri'],
                  'label' => $item['title'],
                ];
            }

            $access = $this
              ->getDoctrine()
              ->getManager()
              ->getRepository('semappsBundle:User')
              ->getAccessLevelString($user);

            $name = ($user != null)? $user->getUsername() : '';
            // If no internet, we use a cached version of services
            // placed int face_service folder.
            if ($this->container->hasParameter('no_internet')) {
                $output = ['no_internet' => 1];
            } else {
                $output = [
                  'access'       => $access,
                  'name'         => $name,
                  'buildings'    => semappsConfig::$buildings,
                  'entities'     => $this->entitiesTabs,
                  'thesaurus'    => $thesaurus,
                ];
            }

            $parameters->set($output);

            $cache->save($parameters);
        //}

        return new JsonResponse($parameters->get());
    }

    public function searchSparqlRequest($term, $type = semappsConfig::Multiple, $filter=null, $isBlocked = false)
    {
        $sfClient    = $this->container->get('semantic_forms.client');
        $arrayType = explode('|',$type);
        $arrayType = array_flip($arrayType);
        $typeOrganization = array_key_exists(semappsConfig::URI_PAIR_ORGANIZATION,$arrayType);
        $typePerson= array_key_exists(semappsConfig::URI_PAIR_PERSON,$arrayType);
        $typeProject= array_key_exists(semappsConfig::URI_PAIR_PROJECT,$arrayType);
        $typeEvent= array_key_exists(semappsConfig::URI_PAIR_EVENT,$arrayType);
        $typeDocument= array_key_exists(semappsConfig::URI_PAIR_DOCUMENT,$arrayType);
        $typeDocumentType= array_key_exists(semappsConfig::URI_PAIR_DOCUMENT_TYPE,$arrayType);
        $typeProposition= array_key_exists(semappsConfig::URI_PAIR_PROPOSAL,$arrayType);
        $typeThesaurus= array_key_exists(semappsConfig::URI_SKOS_THESAURUS,$arrayType);
        //$userLogged =  $this->getUser() != null;
        $sparqlClient = new SparqlClient();
        /** @var \VirtualAssembly\SparqlBundle\Sparql\sparqlSelect $sparql */
        $sparql = $sparqlClient->newQuery(SparqlClient::SPARQL_SELECT);
        /* requete génériques */
        $sparql->addPrefixes($sparql->prefixes)
					->addPrefix('default','http://assemblee-virtuelle.github.io/mmmfest/PAIR_temp.owl#')
            ->addSelect('?uri')
            ->addSelect('?type')
            ->addSelect('?image')
            ->addSelect('?desc')
            ->addSelect('?building');
        ($filter)? $sparql->addWhere('?uri','default:hasInterest',$sparql->formatValue($filter,$sparql::VALUE_TYPE_URL),'?GR' ) : null;
        //($term != '*')? $sparql->addWhere('?uri','text:query',$sparql->formatValue($term,$sparql::VALUE_TYPE_TEXT),'?GR' ) : null;
        $sparql->addWhere('?uri','rdf:type', '?type','?GR')
            ->groupBy('?uri ?type ?title ?image ?desc ?building')
            ->orderBy($sparql::ORDER_ASC,'?title');
        $organizations =[];
        if($type == semappsConfig::Multiple || $typeOrganization ){
            $orgaSparql = clone $sparql;
            $orgaSparql->addSelect('?title')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(semappsConfig::URI_PAIR_ORGANIZATION,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','default:preferedLabel','?title','?GR')
                ->addOptional('?uri','default:image','?image','?GR')
                ->addOptional('?uri','default:comment','?desc','?GR');
                //->addOptional('?uri','default:hostedIn','?building','?GR');
            if($term)$orgaSparql->addFilter('contains( lcase(?title) , lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
            //dump($orgaSparql->getQuery());
            $results = $sfClient->sparql($orgaSparql->getQuery());
            $organizations = $sfClient->sparqlResultsValues($results);
        }
        $persons = [];
        if($type == semappsConfig::Multiple || $typePerson ){

            $personSparql = clone $sparql;
            $personSparql->addSelect('?lastName')
                ->addSelect('?firstName')
                ->addSelect('( COALESCE(?lastName, "") As ?result) (fn:concat(?firstName, " " , ?result) as ?title)')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(semappsConfig::URI_PAIR_PERSON,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','default:firstName','?firstName','?GR')
                ->addOptional('?uri','default:image','?image','?GR')
                ->addOptional('?uri','default:description','?desc','?GR')
                ->addOptional('?uri','default:lastName','?lastName','?GR')
                ->addOptional('?org','rdf:type','default:Organization','?GR');
                //->addOptional('?org','default:hostedIn','?building','?GR');
            if($term)$personSparql->addFilter('contains( lcase(?firstName)+ " " + lcase(?lastName), lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) || contains( lcase(?lastName)  , lcase("'.$term.'")) || contains( lcase(?firstName)  , lcase("'.$term.'")) ');
            $personSparql->groupBy('?firstName ?lastName');
            //dump($personSparql->getQuery());exit;
            $results = $sfClient->sparql($personSparql->getQuery());
            $persons = $sfClient->sparqlResultsValues($results);

        }
        $projects = [];
        if($type == semappsConfig::Multiple || $typeProject ){
            $projectSparql = clone $sparql;
            $projectSparql->addSelect('?title')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(semappsConfig::URI_PAIR_PROJECT,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','default:preferedLabel','?title','?GR')
                ->addOptional('?uri','default:image','?image','?GR')
                ->addOptional('?uri','default:comment','?desc','?GR');
                //->addOptional('?uri','default:building','?building','?GR');
            if($term)$projectSparql->addFilter('contains( lcase(?title) , lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
            $results = $sfClient->sparql($projectSparql->getQuery());
            $projects = $sfClient->sparqlResultsValues($results);

        }
        $events = [];
        if(($type == semappsConfig::Multiple || $typeEvent) ){
            $eventSparql = clone $sparql;
            $eventSparql->addSelect('?title')
                ->addSelect('?start')
                ->addSelect('?end')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(semappsConfig::URI_PAIR_EVENT,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','default:preferedLabel','?title','?GR')
                ->addOptional('?uri','default:image','?image','?GR')
                ->addOptional('?uri','default:comment','?desc','?GR')
                ->addOptional('?uri','default:localizedBy','?building','?GR')
                ->addOptional('?uri','default:startDate','?start','?GR')
                ->addOptional('?uri','default:endDate','?end','?GR');
            if($term)$eventSparql->addFilter('contains( lcase(?title), lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
            $eventSparql->orderBy($sparql::ORDER_ASC,'?start')
                ->groupBy('?start')
                ->groupBy('?end');
            $results = $sfClient->sparql($eventSparql->getQuery());
            $events = $sfClient->sparqlResultsValues($results);

        }
        $propositions = [];
        if(($type == semappsConfig::Multiple || $typeProposition) ){
            $propositionSparql = clone $sparql;
            $propositionSparql->addSelect('?title')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(semappsConfig::URI_PAIR_PROPOSAL,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','default:preferedLabel','?title','?GR')
                ->addOptional('?uri','default:image','?image','?GR')
                ->addOptional('?uri','default:comment','?desc','?GR');
            //$propositionSparql->addOptional('?uri','default:building','?building','?GR');
            if($term)$propositionSparql->addFilter('contains( lcase(?title)  , lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
            $results = $sfClient->sparql($propositionSparql->getQuery());
            $propositions = $sfClient->sparqlResultsValues($results);
        }
				$documents = [];
				if((($type == semappsConfig::Multiple || $typeDocument) ) ){
						$documentSparql = clone $sparql;
						$documentSparql->addSelect('?title')
							->addWhere('?uri','rdf:type', $sparql->formatValue(semappsConfig::URI_PAIR_DOCUMENT,$sparql::VALUE_TYPE_URL),'?GR')
							->addWhere('?uri','default:preferedLabel','?title','?GR')
							->addOptional('?uri','default:comment','?desc','?GR');
						//$documentSparql->addOptional('?uri','default:building','?building','?GR');
						if($term)$documentSparql->addFilter('contains( lcase(?title)  , lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
						$results = $sfClient->sparql($documentSparql->getQuery());
						$documents= $sfClient->sparqlResultsValues($results);
				}
				$documentTypes = [];
				if((($type == semappsConfig::Multiple || $typeDocumentType) && !$isBlocked)){
						$documentTypeSparql = clone $sparql;
						$documentTypeSparql->addSelect('?title')
							->addWhere('?uri','rdf:type', $sparql->formatValue(semappsConfig::URI_PAIR_DOCUMENT_TYPE,$sparql::VALUE_TYPE_URL),'?GR')
							->addWhere('?uri','default:preferedLabel','?title','?GR')
							->addOptional('?uri','default:comment','?desc','?GR');
						//$documentTypeSparql->addOptional('?uri','default:building','?building','?GR');
						if($term)$documentTypeSparql->addFilter('contains( lcase(?title)  , lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
						$results = $sfClient->sparql($documentTypeSparql->getQuery());
						$documentTypes = $sfClient->sparqlResultsValues($results);
				}

        $thematiques = [];
        if($type == semappsConfig::Multiple || $typeThesaurus ){
            $thematiqueSparql = clone $sparql;
            $thematiqueSparql->addSelect('?title')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(semappsConfig::URI_SKOS_THESAURUS,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','skos:prefLabel','?title','?GR');
            if($term)$thematiqueSparql->addFilter('contains( lcase(?title) , lcase("'.$term.'"))');
            $results = $sfClient->sparql($thematiqueSparql->getQuery());
            $thematiques = $sfClient->sparqlResultsValues($results);
        }

        $results = [];

        while ($organizations || $persons || $projects
          || $events  || $thematiques || $propositions || $documents || $documentTypes) {

            if (!empty($organizations)) {
                $results[] = array_shift($organizations);
            }
            else if (!empty($persons)) {
                $results[] = array_shift($persons);
            }
						else if (!empty($projects)) {
                $results[] = array_shift($projects);
            }
						else if (!empty($events)) {
                $results[] = array_shift($events);
            }
						else if (!empty($thematiques)) {
                $results[] = array_shift($thematiques);
            }
						else if (!empty($propositions)) {
								$results[] = array_shift($propositions);
						}
						else if (!empty($documents)) {
								$results[] = array_shift($documents);
						}
						else if  (!empty($documentTypes)) {
								$results[] = array_shift($documentTypes);
						}
        }

        return $results;
    }

    public function searchAction(Request $request)
    {
        // Search
        return new JsonResponse(
          (object)[
            'results' => $this->searchSparqlRequest(
              $request->get('t'),
              ''
              ,$request->get('f'),
							true
            ),
          ]
        );
    }

    public function fieldUriSearchAction(Request $request)
    {
        $output = [];
        // Get results.
        $results = $this->searchSparqlRequest($request->get('QueryString'),$request->get('rdfType'));
        // Transform data to match to uri field (uri => title).
        foreach ($results as $item) {
            $output[$item['uri']] = $item['title'];
        }

        return new JsonResponse((object)$output);
    }

    public function sparqlGetLabel($url, $uriType)
    {
        $sparqlClient = new SparqlClient();
        /** @var \VirtualAssembly\SparqlBundle\Sparql\sparqlSelect $sparql */
        $sparql = $sparqlClient->newQuery(SparqlClient::SPARQL_SELECT);
        $sparql->addPrefixes($sparql->prefixes)
					->addPrefix('default','http://assemblee-virtuelle.github.io/mmmfest/PAIR_temp.owl#')
            ->addSelect('?uri')
            ->addFilter('?uri = <'.$url.'>');

        switch ($uriType) {
            case semappsConfig::URI_PAIR_PERSON :
                $sparql->addSelect('( COALESCE(?lastName, "") As ?result)  (fn:concat(?firstName, " ", ?result) as ?label)')
                    ->addWhere('?uri','default:firstName','?firstName','?gr')
                    ->addOptional('?uri','default:lastName','?lastName','?gr');

                break;
            case semappsConfig::URI_PAIR_ORGANIZATION :
            case semappsConfig::URI_PAIR_PROJECT :
            case semappsConfig::URI_PAIR_PROPOSAL :
            case semappsConfig::URI_PAIR_EVENT :
						case semappsConfig::URI_PAIR_DOCUMENT :
						case semappsConfig::URI_PAIR_DOCUMENT_TYPE :
                $sparql->addSelect('?label')
                    ->addWhere('?uri','default:preferedLabel','?label','?gr');

                break;
            case semappsConfig::URI_SKOS_THESAURUS:
                $sparql->addSelect('?label')
                    ->addWhere('?uri','skos:prefLabel','?label','?gr');
                break;
            default:
                $sparql->addSelect('( COALESCE(?firstName, "") As ?result_1)')
                    ->addSelect('( COALESCE(?lastName, "") As ?result_2)')
                    ->addSelect('( COALESCE(?name, "") As ?result_3)')
                    ->addSelect('( COALESCE(?skos, "") As ?result_4)')
                    ->addSelect('(fn:concat(?result_4,?result_3,?result_2, " ", ?result_1) as ?label)')
                    ->addWhere('?uri','rdf:type','?type','?gr')
                    ->addOptional('?uri','default:firstName','?firstName','?gr')
                    ->addOptional('?uri','default:lastName','?lastName','?gr')
                    ->addOptional('?uri','default:preferedLabel','?name','?gr')
                    ->addOptional('?uri','skos:prefLabel','?skos','?gr')
                    ->addOptional('?uri','default:comment','?desc','?gr')
                    ->addOptional('?uri','default:image','?image','?gr');
                    //->addOptional('?uri','gvoi:building','?building','?gr');
                break;
        }


        $sfClient = $this->container->get('semantic_forms.client');
        // Count buildings.
        //dump($sparql->getQuery());
        $response = $sfClient->sparql($sparql->getQuery());
        if (isset($response['results']['bindings'][0]['label']['value'])) {
            return $response['results']['bindings'][0]['label']['value'];
        }

        return false;
    }

    public function fieldUriLabelAction(Request $request)
    {
        $label = $this->sparqlGetLabel(
          $request->get('uri'),
          semappsConfig::Multiple
        );

        return new JsonResponse(
          (object)['label' => $label]
        );
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function detailAction(Request $request)
    {
        return new JsonResponse(
          (object)[
            'detail' => $this->requestPair($request->get('uri')),
          ]
        );
    }

    public function ressourceAction(Request $request){
        $uri                = $request->get('uri');
        $sfClient           = $this->container->get('semantic_forms.client');
        $nameRessource      = $sfClient->dbPediaLabel($uri);
        $sparqlClient = new SparqlClient();
        /** @var \VirtualAssembly\SparqlBundle\Sparql\sparqlSelect $sparql */
        $sparql = $sparqlClient->newQuery(SparqlClient::SPARQL_SELECT);
        $sparql->addPrefixes($sparql->prefixes)
					->addPrefix('default','http://assemblee-virtuelle.github.io/mmmfest/PAIR_temp.owl#')
            ->addSelect('?type')
            ->addSelect('?uri')
					->addSelect('( COALESCE(?firstName, "") As ?result_1)')
					->addSelect('( COALESCE(?lastName, "") As ?result_2)')
					->addSelect('( COALESCE(?name, "") As ?result_3)')
					->addSelect('( COALESCE(?skos, "") As ?result_4)')
					->addSelect('(fn:concat(?result_4,?result_3,?result_2, " ", ?result_1) as ?label)')
					->addWhere('?uri','rdf:type','?type','?gr')
					->addOptional('?uri','default:firstName','?firstName','?gr')
					->addOptional('?uri','default:lastName','?lastName','?gr')
					->addOptional('?uri','default:preferedLabel','?name','?gr')
					->addOptional('?uri','skos:prefLabel','?skos','?gr')
					->addOptional('?uri','default:comment','?desc','?gr')
					->addOptional('?uri','default:image','?image','?gr');
        $ressourcesNeeded = clone $sparql;
        $ressourcesNeeded->addWhere('?uri','default:needs',$sparql->formatValue($uri,$sparql::VALUE_TYPE_URL),'?gr');

        $requests['ressourcesNeeded'] = $ressourcesNeeded->getQuery();
        $ressourcesProposed = clone $sparql;
        $ressourcesProposed->addWhere('?uri','default:offers',$sparql->formatValue($uri,$sparql::VALUE_TYPE_URL),'?gr');
        $requests['ressourcesProposed'] =$ressourcesProposed->getQuery();


        $filtered['name'] = $nameRessource;
        $filtered['uri'] = $uri;
        foreach ($requests as $key => $request){
            //dump($request);
            $results[$key]  = $sfClient->sparql($request);
            $results[$key] = is_array($results[$key]) ? $sfClient->sparqlResultsValues(
                $results[$key]
            ) : [];
            $filtered[$key] = $this->filter($results[$key]);
        }
        return new JsonResponse(
            (object)[
                'ressource' => $filtered,
            ]
        );
    }

    public function uriPropertiesFiltered($uri)
    {
        $sfClient     = $this->container->get('semantic_forms.client');
        $properties   = $sfClient->uriProperties($uri);
        $output       = [];
        $user         = $this->GetUser();
        $this
          ->getDoctrine()
          ->getManager()
          ->getRepository('semappsBundle:User')
          ->getAccessLevelString($user);
				if(array_key_exists(self::TYPE,$properties)){
						$sfConf = $this->getConf(current($properties[self::TYPE]));
						foreach ($sfConf['fields'] as $field =>$detail){
								if ($detail['access'] === 'anonymous' ||
									$this->isGranted('ROLE_'.strtoupper($detail['access']))
								){
										if (isset($properties[$field])) {
												$output[$detail['value']] = $properties[$field];
										}
								}
						}
				}
        return $output;
    }

    public function requestPair($uri)
    {
        $output     = [];
        $properties = $this->uriPropertiesFiltered($uri);
        $sfClient   = $this->container->get('semantic_forms.client');
        switch (current($properties['type'])) {
            // Orga.
            case  semappsConfig::URI_PAIR_ORGANIZATION:
                // Organization should be saved internally.
                $organization = $this->getDoctrine()->getRepository(
                  'semappsBundle:Organisation'
                )->findOneBy(
                  [
                    'sfOrganisation' => $uri,
                  ]
                );
								if(!is_null($organization))
										$output['id'] = $organization->getId();

								$propertiesWithUri =[
									'hasResponsible',
									'hasMember',
									'employs',
									'affiliates',
									'partnerOf',
									'involvedIn',
									'manages',
									'organizes',
									'participantOf',
									'brainstorms',
									'documentedBy',
										'subjectOfPAIR',
								];
								$this->getData($properties,$propertiesWithUri,$output);
//								if (isset($properties['hostedIn'])) {
//										$properties['building'] = current($properties['hostedIn']);
//										$properties['hostedIn'] = mmmfestConfig::$buildings[current($properties['hostedIn'])]['title'];
//								}
								if (isset($properties['description'])) {
										$properties['description'] = nl2br(current($properties['description']),false);
								}

								if (isset($properties['offers'])) {
										foreach ($properties['offers'] as $uri) {
												$output['offers'][] = [
													'uri'  => $uri,
													'name' => $sfClient->dbPediaLabel($uri),
												];
										}
								}
								if (isset($properties['needs'])) {
										foreach ($properties['needs'] as $uri) {
												$output['needs'][] = [
													'uri'  => $uri,
													'name' => $sfClient->dbPediaLabel($uri),
												];
										}
								}
                break;
            // Person.
						case  semappsConfig::URI_PAIR_PERSON:
//								//TODO: to be modified
//                $query = " SELECT ?b WHERE { GRAPH ?G {<".$uri."> rdf:type default:Person . ?org rdf:type default:Organization . ?org default:hostedIn ?b .} }";
//                //dump($query);
//                $buildingsResult = $sfClient->sparql($sfClient->prefixesCompiled . $query);
//								$output['building'] = (isset($buildingsResult["results"]["bindings"][0])) ? $buildingsResult["results"]["bindings"][0]['b']['value'] : '';
								if (isset($properties['description'])) {
										$properties['description'] = nl2br(current($properties['description']),false);
								}
								$propertiesWithUri = [
									'knows',
									'affiliatedTo',
									'responsibleOf',
									'memberOf',
									'employedBy',
									'involvedIn',
									'manages',
									'participantOf',
									'brainstorms',
									'manages',
									'organizes',
									'documentedBy',
									'subjectOfPAIR',

								];
								$this->getData($properties,$propertiesWithUri,$output);
								if (isset($properties['offers'])) {
										foreach ($properties['offers'] as $uri) {
												$output['offers'][] = [
													'uri'  => $uri,
													'name' => $sfClient->dbPediaLabel($uri),
												];
										}
								}
								if (isset($properties['needs'])) {
										foreach ($properties['needs'] as $uri) {
												$output['needs'][] = [
													'uri'  => $uri,
													'name' => $sfClient->dbPediaLabel($uri),
												];
										}
								}
                break;
            // Project.
            case semappsConfig::URI_PAIR_PROJECT:
								if (isset($properties['description'])) {
										$properties['description'] = nl2br(current($properties['description']),false);
								}

								$propertiesWithUri = [
//									'concretizes',
									'involves',
									'managedBy',
//									'representedBy',
									'documentedBy',
									'subjectOfPAIR',

								];
								if (isset($properties['needs'])) {
										foreach ($properties['needs'] as $uri) {
												$output['needs'][] = [
													'uri'  => $uri,
													'name' => $sfClient->dbPediaLabel($uri),
												];
										}
								}
								if (isset($properties['offers'])) {
										foreach ($properties['offers'] as $uri) {
												$output['offers'][] = [
													'uri'  => $uri,
													'name' => $sfClient->dbPediaLabel($uri),
												];
										}
								}
								$this->getData($properties,$propertiesWithUri,$output);
                break;
            // Event.
            case semappsConfig::URI_PAIR_EVENT:
								if (isset($properties['description'])) {
										$properties['description'] = nl2br(current($properties['description']),false);
								}

								$propertiesWithUri = [
									'organizedBy',
									'hasParticipant',
									'documentedBy',
									'subjectOfPAIR',

								];
								if (isset($properties['localizedBy'])) {
										$properties['building'] = current($properties['localizedBy']);
										$properties['localizedBy'] = semappsConfig::$buildings[current($properties['localizedBy'])]['title'];
								}
								$this->getData($properties,$propertiesWithUri,$output);
                break;
            // Proposition.
            case semappsConfig::URI_PAIR_PROPOSAL:
								if (isset($properties['description'])) {
										$properties['description'] = nl2br(current($properties['description']),false);
								}

								$propertiesWithUri = [
									'brainstormedBy',
									'concretizedBy',
									#'representedBy',
									'documentedBy',
									'hasSubjectPAIR',

								];
								$this->getData($properties,$propertiesWithUri,$output);
                break;
						// document
						case semappsConfig::URI_PAIR_DOCUMENT:
								if (isset($properties['description'])) {
										$properties['description'] = nl2br(current($properties['description']),false);
								}
								$propertiesWithUri = [
									'documents',
									'references',
									'referencesBy',
									'hasType',
									'subjectOfPAIR',
										'internal_author',
										'internal_contributor',
								];
								$this->getData($properties,$propertiesWithUri,$output);
								break;
						//document type
						case semappsConfig::URI_PAIR_DOCUMENT_TYPE:
								if (isset($properties['description'])) {
										$properties['description'] = nl2br(current($properties['description']),false);
								}
								$propertiesWithUri = [
									'typeOf'
								];
								//dump($properties);exit;
								$this->getData($properties,$propertiesWithUri,$output);
								break;
        }
				if (isset($properties['hasSubject'])) {
						foreach ($properties['hasSubject'] as $uri) {
								$output['hasSubject'][] = [
									'uri'  => $uri,
									'name' => $sfClient->dbPediaLabel($uri),
								];
						}
				}
				if (isset($properties['hasInterest'])) {
						foreach ($properties['hasInterest'] as $uri) {
								$result = [
									'uri' => $uri,
									'name' => $this->sparqlGetLabel($uri,semappsConfig::URI_SKOS_THESAURUS)
								];
								$output['hasInterest'][] = $result;
						}
				}
        $output['properties'] = $properties;

        //dump($output);
        return $output;

    }

		private function getData($properties,$tabFieldsAlias,&$output){
				$cacheTemp = [];
				foreach ($tabFieldsAlias as $alias) {
						if (isset($properties[$alias])) {
								foreach ($properties[$alias] as $uri) {
										if (array_key_exists($uri, $cacheTemp)) {
												$output[$alias][$this->entitiesTabs[$cacheTemp[$uri]['type']]['nameType']][] = $cacheTemp[$uri];
										} else {
												$component = $this->uriPropertiesFiltered($uri);
												//dump($component);
												if(array_key_exists('type',$component)){
														$componentType = current($component['type']);
														$result = null;
														switch ($componentType) {
																case semappsConfig::URI_PAIR_PERSON:
																		$result = [
																			'uri' => $uri,
																			'name' => ((current($component['firstName'])) ? current($component['firstName']) : "") . " " . ((current($component['lastName'])) ? current($component['lastName']) : ""),
																			'image' => (!isset($component['image'])) ? '/common/images/no_avatar.jpg' : $component['image'],
																		];
																		$output[$alias][$this->entitiesTabs[$componentType]['nameType']][] = $result;
																		break;
																case semappsConfig::URI_PAIR_ORGANIZATION:
																case semappsConfig::URI_PAIR_PROJECT:
																case semappsConfig::URI_PAIR_EVENT:
																case semappsConfig::URI_PAIR_PROPOSAL:
																case semappsConfig::URI_PAIR_DOCUMENT:
																case semappsConfig::URI_PAIR_DOCUMENT_TYPE:
																		$result = [
																			'uri' => $uri,
																			'name' => ((current($component['preferedLabel'])) ? current($component['preferedLabel']) : ""),
																			'image' => (!isset($component['image'])) ? '/common/images/no_avatar.jpg' : $component['image'],
																		];
																		$output[$alias][$this->entitiesTabs[$componentType]['nameType']][] = $result;
																		break;
														}
														$cacheTemp[$uri] = $result;
														$cacheTemp[$uri]['type'] = $componentType;
												}
										}
								}
						}
				}
		}

    /**
     * Filter only allowed types.
     * @param array $array
     * @return array
     */
    public function filter(Array $array){
        $filtered = [];
        foreach ($array as $result) {
            // Type is sometime missing.
            if (isset($result['type']) && in_array(
                $result['type'],
                $this->entitiesFilters
              )
            ) {
                $filtered[] = $result;
            }
        }

        return $filtered;
    }

    private function getConf($type){

    	$conf = null;
    		switch ($type){
					case semappsConfig::URI_PAIR_PERSON:
							$conf = $this->getParameter('personConf');
							break;
					case semappsConfig::URI_PAIR_ORGANIZATION:
							$conf = $this->getParameter('organisationConf');
							break;
					case semappsConfig::URI_PAIR_PROJECT:
							$conf = $this->getParameter('projectConf');
							break;
					case semappsConfig::URI_PAIR_EVENT:
							$conf = $this->getParameter('eventConf');
							break;
					case semappsConfig::URI_PAIR_PROPOSAL:
							$conf = $this->getParameter('proposalConf');
							break;
					case semappsConfig::URI_PAIR_DOCUMENT:
							$conf = $this->getParameter('documentConf');
							break;
					case semappsConfig::URI_PAIR_DOCUMENT_TYPE:
							$conf = $this->getParameter('documenttypeConf');
							break;
			}
				return $conf;
		}
}
