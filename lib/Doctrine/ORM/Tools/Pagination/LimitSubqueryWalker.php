<?php

/**
 * Doctrine ORM
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE. This license can also be viewed
 * at http://hobodave.com/license.txt
 *
 * @category    DoctrineExtensions
 * @package     DoctrineExtensions\Paginate
 * @author      David Abdemoulaie <dave@hobodave.com>
 * @copyright   Copyright (c) 2010 David Abdemoulaie (http://hobodave.com/)
 * @license     http://hobodave.com/license.txt New BSD License
 */

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use Doctrine\ORM\Query\AST\Functions\IdentityFunction;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\SelectExpression;
use Doctrine\ORM\Query\AST\SelectStatement;

/**
 * Replaces the selectClause of the AST with a SELECT DISTINCT root.id equivalent.
 *
 * @category    DoctrineExtensions
 * @package     DoctrineExtensions\Paginate
 * @author      David Abdemoulaie <dave@hobodave.com>
 * @copyright   Copyright (c) 2010 David Abdemoulaie (http://hobodave.com/)
 * @license     http://hobodave.com/license.txt New BSD License
 */
class LimitSubqueryWalker extends TreeWalkerAdapter
{
    /**
     * ID type hint.
     */
    const IDENTIFIER_TYPE = 'doctrine_paginator.id.type';

    /**
     * Counter for generating unique order column aliases.
     *
     * @var int
     */
    private $_aliasCounter = 0;

    /**
     * Walks down a SelectStatement AST node, modifying it to retrieve DISTINCT ids
     * of the root Entity.
     *
     * @param SelectStatement $AST
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public function walkSelectStatement(SelectStatement $AST)
    {
        $queryComponents = $this->_getQueryComponents();
        // Get the root entity and alias from the AST fromClause
        $from      = $AST->fromClause->identificationVariableDeclarations;
        $rootAlias = $from[0]->rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass = $queryComponents[$rootAlias]['metadata'];
        $selectExpressions = array();

        foreach ($queryComponents as $dqlAlias => $qComp) {
            // Preserve mixed data in query for ordering.
            if (isset($qComp['resultVariable'])) {
                $selectExpressions[] = new SelectExpression($qComp['resultVariable'], $dqlAlias);
                continue;
            }
        }
        
        $identifier = $rootClass->getSingleIdentifierFieldName();
        
        if (isset($rootClass->associationMappings[$identifier])) {
            throw new \RuntimeException("Paginating an entity with foreign key as identifier only works when using the Output Walkers. Call Paginator#setUseOutputWalkers(true) before iterating the paginator.");
        }

        $this->_getQuery()->setHint(
            self::IDENTIFIER_TYPE,
            Type::getType($rootClass->getTypeOfField($identifier))
        );

        $pathExpression = new PathExpression(
            PathExpression::TYPE_STATE_FIELD | PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
            $rootAlias,
            $identifier
        );
        $pathExpression->type = PathExpression::TYPE_STATE_FIELD;

        array_unshift($selectExpressions, new SelectExpression($pathExpression, '_dctrn_id'));
        $AST->selectClause->selectExpressions = $selectExpressions;

        if (isset($AST->orderByClause)) {
            foreach ($AST->orderByClause->orderByItems as $item) {
                if ( ! $item->expression instanceof PathExpression) {
                    continue;
                }
                
                $AST->selectClause->selectExpressions[] = new SelectExpression(
                    $this->createSelectExpressionItem($item->expression),
                    '_dctrn_ord' . $this->_aliasCounter++
                );
            }
        }

        $AST->selectClause->isDistinct = true;
    }
    
    /**
     * Retrieve either an IdentityFunction (IDENTITY(u.assoc)) or a state field (u.name).
     * 
     * @param \Doctrine\ORM\Query\AST\PathExpression $pathExpression
     * 
     * @return \Doctrine\ORM\Query\AST\Functions\IdentityFunction
     */
    private function createSelectExpressionItem(PathExpression $pathExpression)
    {
        if ($pathExpression->type === PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION) {
            $identity = new IdentityFunction('identity');
            
            $identity->pathExpression = clone $pathExpression;
            
            return $identity;
        }
        
        return clone $pathExpression;
    }
}
