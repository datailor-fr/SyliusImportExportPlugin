<?php

//  Requête SQL pour récupérer les associations de produits de l'ancien site.
//
//  select parent_product.reference as ProductCode,
//          assoc_product.reference as AssociatedProducts
//  from ps_accessory
//          left join ps_product parent_product on ps_accessory.id_product_1 = parent_product.id_product
//          left join ps_product assoc_product on ps_accessory.id_product_2 = assoc_product.id_product where parent_product.reference <> '';


namespace FriendsOfSylius\SyliusImportExportPlugin\Processor;

use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use App\Entity\Product\ProductAssociation;
use Sylius\Component\Product\Model\ProductAssociationTypeInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class ProductAssociationProcessor implements ResourceProcessorInterface
{
    private $logger;
    private $productFactory;
    private $productRepository;
    private $productAssociationTypeRepository;
    private $propertyAccessor;
    private $entityManager;
    private $productAssociationRepository;

    public function __construct(
        FactoryInterface $productFactory,
        RepositoryInterface $productRepository,
        RepositoryInterface $productAssociationTypeRepository,
        RepositoryInterface $productAssociationRepository,
        PropertyAccessorInterface $propertyAccessor,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->productFactory = $productFactory;
        $this->productRepository = $productRepository;
        $this->productAssociationTypeRepository = $productAssociationTypeRepository;
        $this->productAssociationRepository = $productAssociationRepository;
        $this->propertyAccessor = $propertyAccessor;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    public function process(array $data): void
    {
        $productCode = $data['ProductCode'];
        $associatedProducts = explode(',', $data['AssociatedProducts']);

        $product = $this->productRepository->findOneBy(['code' => $productCode]);

        if (!$product instanceof ProductInterface) {
            $this->logger->error("[ProductAssociationProcessor] Product with code $productCode not found.");
            return;
        }

        $associationType = $this->productAssociationTypeRepository->findOneBy(['code' => 'cross-selling']);

        if (!$associationType instanceof ProductAssociationTypeInterface) {
            throw new \Exception("Product association type 'cross-selling' not found.");
        }

        foreach ($associatedProducts as $associatedProductCode) {
            $associatedProduct = $this->productRepository->findOneBy(['code' => $associatedProductCode]);
            
            if (!$associatedProduct instanceof ProductInterface) {
                $this->logger->error("[ProductAssociationProcessor] Associated product with code $associatedProductCode not found.");
                continue;
            }
        
            $existingAssociation = $this->productAssociationRepository->findOneBy(['owner' => $product, 'type' => $associationType]);
        
            if ($existingAssociation) {
                if (!$existingAssociation->hasAssociatedProduct($associatedProduct)) {
                    $existingAssociation->addAssociatedProduct($associatedProduct);
                    $this->entityManager->persist($existingAssociation);
                    $this->logger->info("[ProductAssociationProcessor] Updated existing association with new associated product {$associatedProductCode}.");
                } else {
                    $this->logger->info("[ProductAssociationProcessor] The association with product {$associatedProductCode} already exists. Skipped.");
                }
            } else {
                $productAssociation = new ProductAssociation();
                $productAssociation->setType($associationType);
                $productAssociation->setOwner($product);
                $productAssociation->addAssociatedProduct($associatedProduct);
        
                $product->addAssociation($productAssociation);
                $this->entityManager->persist($productAssociation);
                $this->logger->info("[ProductAssociationProcessor] Created new association with product {$associatedProductCode}.");
            }
        }
        
        $this->entityManager->flush();
        
        
    }
}
