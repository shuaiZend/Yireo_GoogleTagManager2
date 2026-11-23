<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Plugin;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Yireo\GoogleTagManager2\Config\Config;
use Yireo\GoogleTagManager2\DataLayer\Tag\Category\CategorySize;
use Yireo\GoogleTagManager2\Logger\Debugger;
use Yireo\GoogleTagManager2\Util\GetCurrentCategoryProducts;

class GetProductsFromCategoryBlockPlugin
{
    private CategorySize $categorySize;
    private GetCurrentCategoryProducts $getCurrentCategoryProducts;
    private Config $config;
    private Debugger $debugger;

    /**
     * GetProductsFromCategoryBlockPlugin constructor.
     * @param CategorySize $categorySize
     * @param GetCurrentCategoryProducts $getCurrentCategoryProducts
     * @param Config $config
     * @param Debugger $debugger
     */
    public function __construct(
        CategorySize $categorySize,
        GetCurrentCategoryProducts $getCurrentCategoryProducts,
        Config $config,
        Debugger $debugger
    ) {
        $this->categorySize = $categorySize;
        $this->getCurrentCategoryProducts = $getCurrentCategoryProducts;
        $this->config = $config;
        $this->debugger = $debugger;
    }

    /**
     * @param ListProduct $listProductBlock
     * @param AbstractCollection $collection
     * @return AbstractCollection
     */
    public function afterGetLoadedProductCollection(
        ListProduct $listProductBlock,
        AbstractCollection $collection
    ): AbstractCollection {
        $maximumCategoryProducts = $this->config->getMaximumCategoryProducts();
        if ($maximumCategoryProducts <= 0) {
            return $collection;
        }

        if (!$collection->isLoaded()) {
            // Athlete2's toolbar can hand an unloaded collection to the grid. Load it here so the
            // data layer and the grid share the same collection instead of emitting an empty list.
            $this->debugger->debug(
                'GetProductsFromCategoryBlockPlugin: product collection is not loaded, loading it now',
                ['maximum_category_products' => $maximumCategoryProducts]
            );
            $collection->load();
        }

        // GetCurrentCategoryProducts stores products keyed by their entity ID, so multiple list
        // blocks on one page (like the Athlete2 theme renders several list templates) do not
        // lead to duplicate items in the view_item_list event.
        $i = 0;
        foreach ($collection as $product) {
            if ($i >= $maximumCategoryProducts) {
                break;
            }

            $this->getCurrentCategoryProducts->addProduct($product);
            $i++;
        }

        // getSize() returns the total size of the category (before pagination), while count()
        // would only report the number of products on the current page.
        $this->categorySize->setSize((int)$collection->getSize());

        return $collection;
    }
}
