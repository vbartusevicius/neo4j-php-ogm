<?php

/*
 * This file is part of the GraphAware Neo4j PHP OGM package.
 *
 * (c) GraphAware Ltd <info@graphaware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GraphAware\Neo4j\OGM;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use GraphAware\Neo4j\Client\Stack;
use GraphAware\Neo4j\OGM\Metadata\RelationshipMetadata;
use GraphAware\Neo4j\OGM\Persister\EntityPersister;
use GraphAware\Neo4j\OGM\Persister\FlushOperationProcessor;
use GraphAware\Neo4j\OGM\Persister\RelationshipEntityPersister;
use GraphAware\Neo4j\OGM\Persister\RelationshipPersister;

class UnitOfWork
{
    const STATE_NEW = 'STATE_NEW';

    const STATE_MANAGED = 'STATE_MANAGED';

    const STATE_DELETED = 'STATE_DELETED';

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\Common\EventManager
     */
    protected $eventManager;

    protected $flushOperationProcessor;

    protected $managedEntities = [];

    protected $entityStates = [];

    protected $hashesMap = [];

    protected $entityIds = [];

    protected $nodesScheduledForCreate = [];

    protected $nodesScheduledForUpdate = [];

    protected $nodesScheduledForDelete = [];

    protected $relationshipsScheduledForCreated = [];

    protected $relationshipsScheduledForDelete = [];

    protected $relEntitiesScheduledForCreate = [];

    protected $relEntitesScheduledForUpdate = [];

    protected $relEntitesScheduledForDelete = [];

    protected $relEntitiesById = [];

    protected $relEntitiesMap = [];

    protected $persisters = [];

    protected $relationshipEntityPersisters = [];

    protected $relationshipPersister;

    protected $entitiesById = [];

    protected $managedRelationshipReferences = [];

    protected $entityStateReferences = [];

    protected $managedRelationshipEntities = [];

    protected $relationshipEntityReferences = [];

    protected $relationshipEntityStates = [];

    protected $reEntityIds = [];

    protected $reEntitiesById = [];

    protected $managedRelationshipEntitiesMap = [];

    public function __construct(EntityManager $manager)
    {
        $this->entityManager = $manager;
        $this->eventManager = $manager->getEventManager();
        $this->relationshipPersister = new RelationshipPersister();
        $this->flushOperationProcessor = new FlushOperationProcessor($this->entityManager);
    }

    public function persist($entity)
    {
        $visited = [];

        $this->doPersist($entity, $visited);
    }

    public function doPersist($entity, array &$visited)
    {
        $oid = spl_object_hash($entity);
        $this->hashesMap[$oid] = $entity;

        if (isset($visited[$oid])) {
            return;
        }

        $visited[$oid] = $entity;
        $entityState = $this->getEntityState($entity, self::STATE_NEW);

        switch ($entityState) {
            case self::STATE_MANAGED:
                //$this->nodesScheduledForUpdate[$oid] = $entity;
                break;
            case self::STATE_NEW:
                $this->nodesScheduledForCreate[$oid] = $entity;
                break;
            case self::STATE_DELETED:
                throw new \LogicException(sprintf('Node has been deleted'));
        }

        $this->cascadePersist($entity, $visited);
        $this->traverseRelationshipEntities($entity, $visited);
    }

    public function cascadePersist($entity, array &$visited)
    {
        $classMetadata = $this->entityManager->getClassMetadataFor(get_class($entity));
        $associations = $classMetadata->getSimpleRelationships();

        foreach ($associations as $association) {
            $value = $association->getValue($entity);
            if (is_array($value) || $value instanceof ArrayCollection || $value instanceof Collection) {
                foreach ($value as $assoc) {
                    $this->persistRelationship($entity, $assoc, $association, $visited);
                }
            } else {
                $entityB = $association->getValue($entity);
                if (is_object($entityB)) {
                    $this->persistRelationship($entity, $entityB, $association, $visited);
                }
            }
        }
    }

    public function persistRelationship($entityA, $entityB, RelationshipMetadata $relationship, array &$visited)
    {
        if ($entityB instanceof Collection || $entityB instanceof ArrayCollection) {
            foreach ($entityB as $e) {
                $aMeta = $this->entityManager->getClassMetadataFor(get_class($entityA));
                $bMeta = $this->entityManager->getClassMetadataFor(get_class($entityB));
                $type = $relationship->isRelationshipEntity() ? $this->entityManager->getRelationshipEntityMetadata($relationship->getRelationshipEntityClass())->getType() : $relationship->getType();
                $hashStr = $aMeta->getIdValue($entityA).$bMeta->getIdValue($entityB).$type.$relationship->getDirection();
                $hash = md5($hashStr);
                if (!array_key_exists($hash, $this->relationshipsScheduledForCreated)) {
                    $this->relationshipsScheduledForCreated[] = [$entityA, $relationship, $e, $relationship->getPropertyName()];
                }
                $this->doPersist($e, $visited);
            }

            return;
        }
        $this->doPersist($entityB, $visited);
        $this->relationshipsScheduledForCreated[] = [$entityA, $relationship, $entityB, $relationship->getPropertyName()];
    }

    public function flush()
    {
        //preFlush
        if ($this->eventManager->hasListeners(Events::PRE_FLUSH)) {
            $this->eventManager->dispatchEvent(Events::PRE_FLUSH, new Event\PreFlushEventArgs($this->entityManager));
        }

        //Detect changes
        $this->detectRelationshipReferenceChanges();
        $this->detectRelationshipEntityChanges();
        $this->detectEntityChanges();
        $statements = [];

        //onFlush
        if ($this->eventManager->hasListeners(Events::ON_FLUSH)) {
            $this->eventManager->dispatchEvent(Events::ON_FLUSH, new Event\OnFlushEventArgs($this->entityManager));
        }

        foreach ($this->nodesScheduledForCreate as $nodeToCreate) {
            $this->traverseRelationshipEntities($nodeToCreate);
            $class = get_class($nodeToCreate);
            $persister = $this->getPersister($class);
            $statements[] = $persister->getCreateQuery($nodeToCreate);
        }

        $tx = $this->entityManager->getDatabaseDriver()->transaction();
        $tx->begin();

        $nodesCreationStack = $this->flushOperationProcessor->processNodesCreationJob($this->nodesScheduledForCreate);
        $results = $tx->runStack($nodesCreationStack);

        foreach ($results as $result) {
            foreach ($result->records() as $record) {
                $oid = $record->get('oid');
                $gid = $record->get('id');
                $this->hydrateGraphId($oid, $gid);
                $this->entitiesById[$gid] = $this->nodesScheduledForCreate[$oid];
                $this->entityIds[$oid] = $gid;
                $this->entityStates[$oid] = self::STATE_MANAGED;
                $this->manageEntityReference($oid);
            }
        }

        $relStack = $this->entityManager->getDatabaseDriver()->stack('rel_create_schedule');
        foreach ($this->relationshipsScheduledForCreated as $relationship) {
            $statement = $this->relationshipPersister->getRelationshipQuery(
                $this->entityIds[spl_object_hash($relationship[0])],
                $relationship[1],
                $this->entityIds[spl_object_hash($relationship[2])]
            );
            $relStack->push($statement->text(), $statement->parameters());
        }

        if (count($this->relationshipsScheduledForDelete) > 0) {
            foreach ($this->relationshipsScheduledForDelete as $toDelete) {
                $statement = $this->relationshipPersister->getDeleteRelationshipQuery($toDelete[0], $toDelete[1], $toDelete[2]);
                $relStack->push($statement->text(), $statement->parameters());
            }
        }
        $tx->runStack($relStack);
        $reStack = Stack::create('rel_entity_create');
        foreach ($this->relEntitiesScheduledForCreate as $oid => $info) {
            $rePersister = $this->getRelationshipEntityPersister(get_class($info[0]));
            $statement = $rePersister->getCreateQuery($info[0], $info[1]);
            $reStack->push($statement->text(), $statement->parameters());
        }
        foreach ($this->relEntitesScheduledForUpdate as $oid => $entity) {
            $rePersister = $this->getRelationshipEntityPersister(get_class($entity));
            $statement = $rePersister->getUpdateQuery($entity);
            $reStack->push($statement->text(), $statement->parameters());
        }
        foreach ($this->relEntitesScheduledForDelete as $o) {
            $statement = $this->getRelationshipEntityPersister(get_class($o))->getDeleteQuery($o);
            $reStack->push($statement->text(), $statement->parameters());
        }
        $tx->runStack($reStack);

        $updateNodeStack = Stack::create('update_nodes');
        foreach ($this->nodesScheduledForUpdate as $entity) {
            $this->traverseRelationshipEntities($entity);
            $statement = $this->getPersister(get_class($entity))->getUpdateQuery($entity);
            $updateNodeStack->push($statement->text(), $statement->parameters());
        }
        $tx->pushStack($updateNodeStack);

        $deleteNodeStack = Stack::create('delete_nodes');
        $possiblyDeleted = [];
        foreach ($this->nodesScheduledForDelete as $entity) {
            $statement = $this->getPersister(get_class($entity))->getDeleteQuery($entity);
            $deleteNodeStack->push($statement->text(), $statement->parameters());
            $possiblyDeleted[] = spl_object_hash($entity);
        }
        $tx->pushStack($deleteNodeStack);

        $tx->commit();

        foreach ($this->relationshipsScheduledForCreated as $rel) {
            $aoid = spl_object_hash($rel[0]);
            $boid = spl_object_hash($rel[2]);
            $field = $rel[3];
            $this->managedRelationshipReferences[$aoid][$field][] = [
                'entity' => $aoid,
                'target' => $boid,
                'rel' => $rel[1],
            ];
        }

        foreach ($possiblyDeleted as $oid) {
            $this->entityStates[$oid] = self::STATE_DELETED;
        }

        //postFlush
        if ($this->eventManager->hasListeners(Events::POST_FLUSH)) {
            $this->eventManager->dispatchEvent(Events::POST_FLUSH, new Event\PostFlushEventArgs($this->entityManager));
        }

        $this->nodesScheduledForCreate
            = $this->nodesScheduledForUpdate
            = $this->nodesScheduledForDelete
            = $this->relationshipsScheduledForCreated
            = $this->relationshipsScheduledForDelete
            = $this->relEntitesScheduledForUpdate
            = $this->relEntitiesScheduledForCreate
            = $this->relEntitesScheduledForDelete
            = [];
    }

    private function manageEntityReference($oid)
    {
        $id = $this->entityIds[$oid];
        $entity = $this->entitiesById[$id];
        $this->entityStateReferences[$id] = clone $entity;
    }

    public function detectEntityChanges()
    {
        $managed = [];
        foreach ($this->entityStates as $oid => $state) {
            if ($state === self::STATE_MANAGED) {
                $managed[] = $oid;
            }
        }

        foreach ($managed as $oid) {
            $id = $this->entityIds[$oid];
            $entityA = $this->entitiesById[$id];
            $visited = [];
            $this->doPersist($entityA, $visited);
            $entityB = $this->entityStateReferences[$id];
            $this->computeChanges($entityA, $entityB);
        }
    }

    private function computeChanges($entityA, $entityB)
    {
        $classMetadata = $this->entityManager->getClassMetadataFor(get_class($entityA));
        $propertyFields = array_merge($classMetadata->getPropertiesMetadata(), $classMetadata->getLabeledProperties());
        foreach ($propertyFields as $field => $meta) {
            // force proxy to initialize (only needed with proxy manager 1.x
            $reflClass = new \ReflectionClass($classMetadata->getClassName());
            foreach ($reflClass->getMethods() as $method) {
                if ($method->getNumberOfRequiredParameters() === 0 && $method->getName() === 'getId') {
                    $entityA->getId();
                }
            }
            $p1 = $meta->getValue($entityA);
            $p2 = $meta->getValue($entityB);
            if ($p1 !== $p2) {
                $this->nodesScheduledForUpdate[spl_object_hash($entityA)] = $entityA;
            }
        }
    }

    public function addManagedRelationshipReference($entityA, $entityB, $field, RelationshipMetadata $relationship)
    {
        $aoid = spl_object_hash($entityA);
        $boid = spl_object_hash($entityB);
        $this->managedRelationshipReferences[$aoid][$field][] = [
            'entity' => $aoid,
            'target' => $boid,
            'rel' => $relationship,
        ];
        $this->addManaged($entityA);
        $this->addManaged($entityB);
        //print_r($this->managedRelationshipReferences);
    }

    public function detectRelationshipEntityChanges()
    {
        $managed = [];
        foreach ($this->relationshipEntityStates as $oid => $state) {
            if ($state === self::STATE_MANAGED) {
                $managed[] = $oid;
            }
        }

        foreach ($managed as $oid) {
            $reA = $this->reEntitiesById[$this->reEntityIds[$oid]];
            $reB = $this->relationshipEntityReferences[$this->reEntityIds[$oid]];
            $this->computeRelationshipEntityChanges($reA, $reB);
            $this->checkRelationshipEntityDeletions($reA);
        }
    }

    private function computeRelationshipEntityChanges($entityA, $entityB)
    {
        $classMetadata = $this->entityManager->getRelationshipEntityMetadata(get_class($entityA));
        foreach ($classMetadata->getPropertiesMetadata() as $meta) {
            if ($meta->getValue($entityA) !== $meta->getValue($entityB)) {
                $this->relEntitesScheduledForUpdate[spl_object_hash($entityA)] = $entityA;
            }
        }
    }

    public function addManagedRelationshipEntity($entity, $pointOfView, $field)
    {
        $id = $this->entityManager->getRelationshipEntityMetadata(get_class($entity))->getIdValue($entity);
        $oid = spl_object_hash($entity);
        $this->relationshipEntityStates[$oid] = self::STATE_MANAGED;
        $ref = clone $entity;
        $this->reEntitiesById[$id] = $entity;
        $this->reEntityIds[$oid] = $id;
        $this->relationshipEntityReferences[$id] = $ref;
        $poid = spl_object_hash($pointOfView);
        $this->managedRelationshipEntities[$poid][$field][] = $oid;
        $this->managedRelationshipEntitiesMap[$oid][$poid] = $field;
    }

    public function getRelationshipEntityById($id)
    {
        if (array_key_exists($id, $this->reEntitiesById)) {
            return $this->reEntitiesById[$id];
        }

        return null;
    }

    private function checkRelationshipEntityDeletions($entity)
    {
        $oid = spl_object_hash($entity);
        $id = $this->entityManager->getRelationshipEntityMetadata(get_class($entity))->getIdValue($entity);
        foreach ($this->managedRelationshipEntitiesMap[$oid] as $pov => $field) {
            $e = $this->entitiesById[$this->entityIds[$pov]];
            $entityMetadata = $this->entityManager->getClassMetadataFor(get_class($e));
            $values = $entityMetadata->getRelationship($field)->getValue($e);
            $shouldBeDeleted = true;
            foreach ($values as $v) {
                $id2 = $this->entityManager->getRelationshipEntityMetadata(get_class($entity))->getIdValue($v);
                if ($id2 === $id) {
                    $shouldBeDeleted = false;
                }
            }
            if ($shouldBeDeleted) {
                $this->relEntitesScheduledForDelete[] = $entity;
            }
        }
    }

    public function detectRelationshipReferenceChanges()
    {
        foreach ($this->managedRelationshipReferences as $oid => $reference) {
            $entity = $this->entitiesById[$this->entityIds[$oid]];
            $reflO = $this->entityManager->getClassMetadataFor(get_class($entity));
            foreach ($reference as $field => $info) {
                $property = $reflO->getRelationship($field);
                $value = $property->getValue($entity);
                if ($value instanceof ArrayCollection || $value instanceof AbstractLazyCollection) {
                    $value = $value->toArray();
                }
                if (is_array($value)) {
                    $currentValue = array_map(function ($ref) {
                        return $this->entitiesById[$this->entityIds[$ref['target']]];
                    }, $info);

                    $compare = function ($a, $b) {
                        if ($a === $b) {
                            return 0;
                        }

                        return $a < $b ? -1 : 1;
                    };

                    $added = array_udiff($value, $currentValue, $compare);
                    $removed = array_udiff($currentValue, $value, $compare);
                    foreach ($added as $add) {
                        // Since this is the same property, it should be ok to re-use the first relationship
                        $this->scheduleRelationshipReferenceForCreate($entity, $add, $info[0]['rel']);
                    }
                    foreach ($removed as $remove) {
                        $this->scheduleRelationshipReferenceForDelete($entity, $remove, $info[0]['rel']);
                    }
                } elseif (is_object($value)) {
                    $target = $this->entitiesById[$this->entityIds[$info[0]['target']]];
                    if ($value !== $target) {
                        $this->scheduleRelationshipReferenceForDelete($entity, $target, $info[0]['rel']);
                        $this->scheduleRelationshipReferenceForCreate($entity, $value, $info[0]['rel']);
                    }
                } elseif ($value === null) {
                    foreach ($info as $ref) {
                        $target = $this->entitiesById[$this->entityIds[$ref['target']]];
                        $this->scheduleRelationshipReferenceForDelete($entity, $target, $ref['rel']);
                    }
                }
            }
        }
    }

    public function scheduleRelationshipReferenceForCreate($entity, $target, RelationshipMetadata $relationship)
    {
        $this->relationshipsScheduledForCreated[] = [$entity, $relationship, $target, $relationship->getPropertyName()];
    }

    public function scheduleRelationshipReferenceForDelete($entity, $target, RelationshipMetadata $relationship)
    {
        $eClass = $this->entityManager->getClassMetadataFor(get_class($entity));
        $tClass = $this->entityManager->getClassMetadataFor(get_class($target));
        $this->relationshipsScheduledForDelete[] = [$eClass->getIdValue($entity), $tClass->getIdValue($target), $relationship];
    }

    public function traverseRelationshipEntities($entity, array &$visited = [])
    {
        $classMetadata = $this->entityManager->getClassMetadataFor(get_class($entity));
        foreach ($classMetadata->getRelationshipEntities() as $relationshipMetadata) {
            $value = $relationshipMetadata->getValue($entity);
            if (null === $value || ($relationshipMetadata->isCollection() && count($value) === 0)) {
                continue;
            }
            if ($relationshipMetadata->isCollection()) {
                foreach ($value as $v) {
                    $this->persistRelationshipEntity($v, get_class($entity));
                    $rem = $this->entityManager->getRelationshipEntityMetadata(get_class($v));
                    $toPersistProperty = $rem->getStartNode() === $classMetadata->getClassName() ? $rem->getEndNodeValue($v) : $rem->getStartNodeValue($v);
                    $this->doPersist($toPersistProperty, $visited);
                }
            } else {
                $this->persistRelationshipEntity($value, get_class($entity));
                $rem = $this->entityManager->getRelationshipEntityMetadata(get_class($value));
                $toPersistProperty = $rem->getStartNode() === $classMetadata->getClassName() ? $rem->getEndNodeValue($value) : $rem->getStartNodeValue($value);
                $this->doPersist($toPersistProperty, $visited);
            }
        }
    }

    public function persistRelationshipEntity($entity, $pov)
    {
        $oid = spl_object_hash($entity);

        if (!array_key_exists($oid, $this->relationshipEntityStates)) {
            $this->relEntitiesScheduledForCreate[$oid] = [$entity, $pov];
            $this->relationshipEntityStates[$oid] = self::STATE_NEW;
        }
    }

    public function getEntityState($entity, $assumedState = null)
    {
        $oid = spl_object_hash($entity);

        if (isset($this->entityStates[$oid])) {
            return $this->entityStates[$oid];
        }

        if (null !== $assumedState) {
            return $assumedState;
        }

        $id = $this->entityManager->getClassMetadataFor(get_class($entity))->getIdValue($entity);

        if (!$id) {
            return self::STATE_NEW;
        }

        throw new \LogicException('entity state cannot be assumed');
    }

    public function addManaged($entity)
    {
        $oid = spl_object_hash($entity);
        $classMetadata = $this->entityManager->getClassMetadataFor(get_class($entity));
        $id = $classMetadata->getIdValue($entity);
        if (null === $id) {
            throw new \LogicException('Entity marked for managed but could not find identity');
        }
        $this->entityStates[$oid] = self::STATE_MANAGED;
        $this->entityIds[$oid] = $id;
        $this->entitiesById[$id] = $entity;
        $this->manageEntityReference($oid);
    }

    public function scheduleDelete($entity)
    {
        $oid = spl_object_hash($entity);
        $this->nodesScheduledForDelete[] = $entity;
    }

    /**
     * @param int $id
     *
     * @return object|null
     */
    public function getEntityById($id)
    {
        //var_dump($id);
        return isset($this->entitiesById[$id]) ? $this->entitiesById[$id] : null;
    }

    /**
     * @param $class
     *
     * @return Persister\EntityPersister
     */
    public function getPersister($class)
    {
        if (!array_key_exists($class, $this->persisters)) {
            $classMetadata = $this->entityManager->getClassMetadataFor($class);
            $this->persisters[$class] = new EntityPersister($class, $classMetadata);
        }

        return $this->persisters[$class];
    }

    /**
     * @param $class
     *
     * @return \GraphAware\Neo4j\OGM\Persister\RelationshipEntityPersister
     */
    public function getRelationshipEntityPersister($class)
    {
        if (!array_key_exists($class, $this->relationshipEntityPersisters)) {
            $classMetadata = $this->entityManager->getRelationshipEntityMetadata($class);
            $this->relationshipEntityPersisters[$class] = new RelationshipEntityPersister($this->entityManager, $class, $classMetadata);
        }

        return $this->relationshipEntityPersisters[$class];
    }

    public function hydrateGraphId($oid, $gid)
    {
        $refl0 = new \ReflectionObject($this->nodesScheduledForCreate[$oid]);
        $p = $refl0->getProperty('id');
        $p->setAccessible(true);
        $p->setValue($this->nodesScheduledForCreate[$oid], $gid);
    }

    /**
     * @return array
     */
    public function getNodesScheduledForCreate()
    {
        return $this->nodesScheduledForCreate;
    }

    /**
     * @return array
     */
    public function getNodesScheduledForUpdate()
    {
        return $this->nodesScheduledForUpdate;
    }

    /**
     * @return array
     */
    public function getNodesScheduledForDelete()
    {
        return $this->nodesScheduledForDelete;
    }

    /**
     * @return array
     */
    public function getRelationshipsScheduledForCreated()
    {
        return $this->relationshipsScheduledForCreated;
    }

    /**
     * @return array
     */
    public function getRelationshipsScheduledForDelete()
    {
        return $this->relationshipsScheduledForDelete;
    }

    /**
     * @return array
     */
    public function getRelEntitiesScheduledForCreate()
    {
        return $this->relEntitiesScheduledForCreate;
    }

    /**
     * @return array
     */
    public function getRelEntitesScheduledForUpdate()
    {
        return $this->relEntitesScheduledForUpdate;
    }

    /**
     * @return array
     */
    public function getRelEntitesScheduledForDelete()
    {
        return $this->relEntitesScheduledForDelete;
    }

    /**
     * Get the original state of an entity when it was loaded from the database.
     *
     * @param int $id
     *
     * @return object|null
     */
    public function getOriginalEntityState($id)
    {
        if (isset($this->entityStateReferences[$id])) {
            return $this->entityStateReferences[$id];
        }

        return null;
    }
}
