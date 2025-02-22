<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile ElasticSuite to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile\ElasticsuiteVirtualCategory
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2020 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */
namespace Smile\ElasticsuiteVirtualCategory\Model;

use Magento\Catalog\Model\Category;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Smile\ElasticsuiteCatalogRule\Model\Data\ConditionFactory as ConditionDataFactory ;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Smile\ElasticsuiteVirtualCategory\Api\Data\VirtualRuleInterface;
use Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\Product\QueryBuilder;
use Smile\ElasticsuiteVirtualCategory\Model\ResourceModel\VirtualCategory\CollectionFactory;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\ProductFactory as ProductConditionFactory;
use Smile\ElasticsuiteCatalogRule\Model\Rule\Condition\CombineFactory as CombineConditionFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Magento\Framework\Model\Context;

/**
 * Virtual category rule.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @category Smile
 * @package  Smile\ElasticsuiteVirtualCategory
 * @author   Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class Rule extends \Smile\ElasticsuiteCatalogRule\Model\Rule implements VirtualRuleInterface
{
    /**
     * @var QueryFactory
     */
    private $queryFactory;

    /**
     * @var ProductConditionFactory
     */
    private $productConditionsFactory;

    /**
     * @var CategoryFactory
     */
    private $categoryFactory;

    /**
     * @var CollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var QueryBuilder
     */
    private $queryBuilder;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Category[]
     */
    protected $instances = [];

    /**
     * Constructor.
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     *
     * @param Context                 $context                   Context.
     * @param Registry                $registry                  Registry.
     * @param FormFactory             $formFactory               Form factory.
     * @param TimezoneInterface       $localeDate                Locale date.
     * @param CombineConditionFactory $combineConditionsFactory  Search engine rule (combine) condition factory.
     * @param ConditionDataFactory    $conditionDataFactory      Condition Data factory.
     * @param ProductConditionFactory $productConditionsFactory  Search engine rule (product) condition factory.
     * @param QueryFactory            $queryFactory              Search query factory.
     * @param CategoryFactory         $categoryFactory           Product category factorty.
     * @param CollectionFactory       $categoryCollectionFactory Virtual categories collection factory.
     * @param QueryBuilder            $queryBuilder              Search rule query builder.
     * @param StoreManagerInterface   $storeManagerInterface     Store Manager
     * @param array                   $data                      Additional data.
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        TimezoneInterface $localeDate,
        CombineConditionFactory $combineConditionsFactory,
        ConditionDataFactory  $conditionDataFactory,
        ProductConditionFactory $productConditionsFactory,
        QueryFactory $queryFactory,
        CategoryFactory $categoryFactory,
        CollectionFactory $categoryCollectionFactory,
        QueryBuilder $queryBuilder,
        StoreManagerInterface $storeManagerInterface,
        array $data = []
    ) {
        $this->queryFactory              = $queryFactory;
        $this->productConditionsFactory  = $productConditionsFactory;
        $this->categoryFactory           = $categoryFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->queryBuilder              = $queryBuilder;
        $this->storeManager              = $storeManagerInterface;

        parent::__construct($context, $registry, $formFactory, $localeDate, $combineConditionsFactory, $conditionDataFactory, $data);
    }

    /**
     * Implementation of __toString().
     * This one is mandatory to ensure the object is properly handled when it get sent "as is" to a DB query.
     * This occurs especially in @see \Magento\Catalog\Model\Indexer\Category\Flat\Action\Rows::reindex() (line 86 & 98)
     *
     * @return string
     */
    public function __toString(): string
    {
        return json_encode($this->getConditions()->asArray());
    }

    /**
     * Build search query by category.
     *
     * @param CategoryInterface $category           Search category.
     * @param array             $excludedCategories Categories that should not be used into search query building.
     *                                              Used to avoid infinite recursion while building virtual categories rules.
     *
     * @return QueryInterface|null
     */
    public function getCategorySearchQuery($category, $excludedCategories = []): ?QueryInterface
    {
        $query         = null;

        if (!is_object($category)) {
            $category = $this->categoryFactory->create()->setStoreId($this->getStoreId())->load($category);
        }

        if (!in_array($category->getId(), $excludedCategories)) {
            $excludedCategories[] = $category->getId();

            if ((bool) $category->getIsVirtualCategory() && $category->getIsActive()) {
                $query = $this->getVirtualCategoryQuery($category, $excludedCategories);
            } elseif ($category->getId() && $category->getIsActive()) {
                $query = $this->getStandardCategoryQuery($category, $excludedCategories);
            }
            if ($query && $category->hasChildren()) {
                $query = $this->addChildrenQueries($query, $category, $excludedCategories);
            }
        }

        return $query;
    }

    /**
     * Retrieve search queries of children categories.
     *
     * @param CategoryInterface $rootCategory Root category.
     *
     * @return QueryInterface[]
     */
    public function getSearchQueriesByChildren(CategoryInterface $rootCategory): array
    {
        $queries     = [];
        $childrenIds = $rootCategory->getResource()->getChildren($rootCategory, false);

        if (!empty($childrenIds)) {
            $storeId            = $this->getStoreId();
            $categoryCollection = $this->categoryCollectionFactory->create()->setStoreId($storeId);

            $categoryCollection
                ->setStoreId($this->getStoreId())
                ->addIsActiveFilter()
                ->addIdFilter($childrenIds)
                ->addAttributeToSelect(['virtual_category_root', 'is_virtual_category', 'virtual_rule']);

            foreach ($categoryCollection as $category) {
                $childQuery = $this->getCategorySearchQuery($category);
                if ($childQuery !== null) {
                    $queries[$category->getId()] = $childQuery;
                }
            }
        }

        return $queries;
    }

    /**
     * {@inheritDoc}
     */
    public function getCondition()
    {
        $conditions = $this->getConditions()->asArray();

        return $this->arrayToConditionDataModel($conditions);
    }

    /**
     * {@inheritDoc}
     */
    public function setCondition($condition)
    {
        $this->getConditions()->setConditions([])->loadArray($this->dataModelToArray($condition));

        return $this;
    }

    /**
     * Combine several category queries
     *
     * @param CategoryInterface[] $categories The categories
     *
     * @return QueryInterface
     */
    public function mergeCategoryQueries(array $categories)
    {
        $queries = [];

        foreach ($categories as $category) {
            $queries[] = $this->getCategorySearchQuery($category);
        }

        return $this->queryFactory->create(QueryInterface::TYPE_BOOL, ['must' => $queries]);
    }

    /**
     * Load the root category used for a virtual category.
     *
     * @param CategoryInterface $category Virtual category.
     *
     * @return CategoryInterface|null
     * @throws NoSuchEntityException
     */
    private function getVirtualRootCategory(CategoryInterface $category): ?CategoryInterface
    {
        $storeId      = $this->getStoreId();
        $rootCategory = $this->categoryFactory->create()->setStoreId($storeId);

        if ($category->getVirtualCategoryRoot() !== null && !empty($category->getVirtualCategoryRoot())) {
            $rootCategoryId = $category->getVirtualCategoryRoot();
            try {
                $rootCategory = $this->getRootCategory($rootCategoryId, $storeId);
            } catch (NoSuchEntityException $e) {
                $rootCategory = null;
            }
        }

        if ($rootCategory && $rootCategory->getId()
            && ($rootCategory->getLevel() < 1 || (int) $rootCategory->getId() === (int) $this->getTreeRootCategoryId($category))
        ) {
            $rootCategory = null;
        }

        return $rootCategory;
    }

    /**
     * Get info about category by category id.
     * This code uses a local cache for better performance.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @param int      $rootCategoryId Root category id.
     * @param int|null $storeId        Store id.
     * @return CategoryInterface
     * @throws NoSuchEntityException
     */
    private function getRootCategory(int $rootCategoryId, int $storeId = null)
    {
        $cacheKey = $storeId ?? 'all';
        if (!isset($this->instances[$rootCategoryId][$cacheKey])) {
            $rootCategory = $this->categoryFactory->create();
            if (null !== $storeId) {
                $rootCategory->setStoreId($storeId);
            }
            $rootCategory->load($rootCategoryId);
            if (!$rootCategory->getId()) {
                throw NoSuchEntityException::singleField('id', $rootCategoryId);
            }
            $this->instances[$rootCategoryId][$cacheKey] = $rootCategory;
        }

        return $this->instances[$rootCategoryId][$cacheKey];
    }

    /**
     * Transform a category in query rule.
     *
     * @param CategoryInterface $category           Category.
     * @param array             $excludedCategories Categories ignored in subquery filters.
     *
     * @return QueryInterface
     */
    private function getStandardCategoryQuery(CategoryInterface $category, $excludedCategories = []): QueryInterface
    {
        return $this->getStandardCategoriesQuery([$category->getId()], $excludedCategories);
    }

    /**
     * Transform a category ids list in query rule.
     *
     * @param array $categoryIds        Categories included in the query.
     * @param array $excludedCategories Categories ignored in subquery filters.
     *
     * @return QueryInterface
     */
    private function getStandardCategoriesQuery(array $categoryIds, $excludedCategories): QueryInterface
    {
        $conditionsParams  = ['data' => ['attribute' => 'category_ids', 'operator' => '()', 'value' => $categoryIds]];
        $categoryCondition = $this->productConditionsFactory->create($conditionsParams);

        return $this->queryBuilder->getSearchQuery($categoryCondition, $excludedCategories);
    }

    /**
     * Transform the virtual category into a QueryInterface used for filtering.
     *
     * @param CategoryInterface $category           Virtual category.
     * @param array             $excludedCategories Category already used into the building stack. Avoid short circuit.
     *
     * @return QueryInterface
     */
    private function getVirtualCategoryQuery(
        CategoryInterface $category,
        $excludedCategories = []
    ): ?QueryInterface {
        $query        = $category->getVirtualRule()->getConditions()->getSearchQuery($excludedCategories);
        $rootCategory = $this->getVirtualRootCategory($category);

        if ($rootCategory && in_array($rootCategory->getId(), $excludedCategories)) {
            $query = null;
        }
        if ($rootCategory && $rootCategory->getId()) {
            $rootQuery = $this->getCategorySearchQuery($rootCategory, $excludedCategories);
            if ($rootQuery) {
                $query = $this->queryFactory->create(QueryInterface::TYPE_BOOL, ['must' => [$query, $rootQuery]]);
            }
        }

        return $query;
    }

    /**
     * Append children queries to the rule.
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     *
     * @param QueryInterface|NULL $query              Base query.
     * @param CategoryInterface   $category           Current cayegory.
     * @param array               $excludedCategories Category already used into the building stack. Avoid short circuit.
     *
     * @return \Smile\ElasticsuiteCore\Search\Request\QueryInterface
     */
    private function addChildrenQueries($query, CategoryInterface $category, $excludedCategories = []): QueryInterface
    {
        $childrenCategories    = $this->getChildrenCategories($category, $excludedCategories);
        $childrenCategoriesIds = [];

        if ($query !== null && $childrenCategories->getSize() > 0) {
            $queryParams = ['should' => [$query], 'cached' => empty($excludedCategories)];

            foreach ($childrenCategories as $childrenCategory) {
                if (((bool) $childrenCategory->getIsVirtualCategory()) === true) {
                    $childrenQuery = $this->getCategorySearchQuery($childrenCategory, $excludedCategories);
                    if ($childrenQuery !== null) {
                        $queryParams['should'][] = $childrenQuery;
                    }
                } else {
                    $childrenCategoriesIds[] = $childrenCategory->getId();
                }
            }

            if (!empty($childrenCategoriesIds)) {
                $queryParams['should'][] = $this->getStandardCategoriesQuery($childrenCategoriesIds, $excludedCategories);
            }

            if (count($queryParams['should']) > 1) {
                $query = $this->queryFactory->create(QueryInterface::TYPE_BOOL, $queryParams);
            }
        }

        return $query;
    }

    /**
     * Returns the list of the virtual categories available under a category.
     *
     * @param CategoryInterface $category           Category.
     * @param array             $excludedCategories Category already used into the building stack. Avoid short circuit.
     *
     * @return Collection
     */
    private function getChildrenCategories(CategoryInterface $category, $excludedCategories = []): Collection
    {
        $storeId            = $category->getStoreId();
        $categoryCollection = $this->categoryCollectionFactory->create()->setStoreId($storeId);

        $categoryCollection->addIsActiveFilter()->addPathFilter(sprintf('%s/.*', $category->getPath()));

        if (((bool) $category->getIsVirtualCategory()) === false) {
            $categoryCollection->addFieldToFilter('is_virtual_category', '1');
        }

        if (!empty($excludedCategories)) {
            $categoryCollection->addAttributeToFilter('entity_id', ['nin' => $excludedCategories]);
        }

        $categoryCollection->addAttributeToSelect(['is_active', 'virtual_category_root', 'is_virtual_category', 'virtual_rule']);

        return $categoryCollection;
    }

    /**
     * Retrieve the root category id of the tree the category belongs to.
     *
     * @param CategoryInterface $category Category.
     *
     * @return int
     */
    private function getTreeRootCategoryId($category): int
    {
        $rootCategoryId = 0;

        $pathIds = $category->getPathIds();
        if (count($pathIds) > 1) {
            $rootCategoryId = (int) current(array_slice($pathIds, 1, 1));
        }

        return $rootCategoryId;
    }
}
