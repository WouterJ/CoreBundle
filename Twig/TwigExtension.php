<?php

namespace Symfony\Cmf\Bundle\CoreBundle\Twig;

use PHPCR\Util\PathHelper;
use Symfony\Cmf\Bundle\CoreBundle\PublishWorkflow\PublishWorkflowCheckerInterface;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ODM\PHPCR\Exception\MissingTranslationException;
use Doctrine\ODM\PHPCR\DocumentManager;

class TwigExtension extends \Twig_Extension
{
    /**
     * @var DocumentManager
     */
    protected $dm;

    /**
     * @var PublishWorkflowCheckerInterface
     */
    protected $publishWorkflowChecker;

    /**
     * Instantiate the content controller.
     *
     * @param PublishWorkflowCheckerInterface $publishWorkflowChecker
     * @param ManagerRegistry $registry
     * @param string $objectManagerName
     */
    public function __construct(PublishWorkflowCheckerInterface $publishWorkflowChecker, $registry = null, $objectManagerName = null)
    {
        $this->publishWorkflowChecker = $publishWorkflowChecker;

        if ($registry && $registry instanceof ManagerRegistry) {
            $this->dm = $registry->getManager($objectManagerName);
        }
    }

    public function getFunctions()
    {
        $functions = array('cmf_is_published' => new \Twig_Function_Method($this, 'isPublished'));

        if ($this->dm) {
            $functions['cmf_child'] = new \Twig_Function_Method($this, 'child');
            $functions['cmf_children'] = new \Twig_Function_Method($this, 'children');
            $functions['cmf_prev'] = new \Twig_Function_Method($this, 'prev');
            $functions['cmf_next'] = new \Twig_Function_Method($this, 'next');
            $functions['cmf_find'] = new \Twig_Function_Method($this, 'find');
            $functions['cmf_nodename'] = new \Twig_Function_Method($this, 'getNodeName');
            $functions['cmf_parent_path'] = new \Twig_Function_Method($this, 'getParentPath');
            $functions['cmf_path'] = new \Twig_Function_Method($this, 'getPath');
            $functions['cmf_document_locales'] = new \Twig_Function_Method($this, 'getLocalesFor');

            if (interface_exists('Symfony\Cmf\Component\Routing\RouteAwareInterface')) {
                $functions['cmf_prev_linkable'] = new \Twig_Function_Method($this, 'prevLinkable');
                $functions['cmf_next_linkable'] = new \Twig_Function_Method($this, 'nextLinkable');
                $functions['cmf_children_linkable'] = new \Twig_Function_Method($this, 'childrenLinkable');
            }
        }

        return $functions;
    }

    public function getNodeName($document)
    {
        return PathHelper::getNodeName($this->getPath($document));
    }

    public function getParentPath($document)
    {
        return PathHelper::getParentPath($this->getPath($document));
    }

    public function getPath($document)
    {
        return $this->dm->getUnitOfWork()->getDocumentId($document);
    }

    public function child($parent, $name)
    {
        $parentId = $this->dm->getUnitOfWork()->getDocumentId($parent);
        return $this->dm->find(null, $parentId.'/'.$name);
    }

    public function childrenLinkable($parent, $limit = false, $ignoreRole = false, $filter = null)
    {
        return $this->children($parent, $limit, $ignoreRole, $filter, 'Symfony\Cmf\Component\Routing\RouteAwareInterface');
    }

    /**
     * @param object $parent parent document
     * @param int|bool $limit int limit or false
     * @param string|bool $offset string node name to which to skip to or false
     * @param bool|null $ignoreRole boolean if the role should be ignored or null if publish workflow should be ignored
     * @param string|null $filter child filter
     * @param string|null $class class name to filter on
     * @return array
     */
    public function children($parent, $limit = false, $offset = false, $ignoreRole = false, $filter = null, $class = null)
    {
        if (empty($parent)) {
            return array();
        }

        if ($limit || $offset) {
            $parentId = $this->dm->getUnitOfWork()->getDocumentId($parent);
            $node = $this->dm->getPhpcrSession()->getNode($parentId);
            $children = (array) $node->getNodeNames();
            if ($offset) {
                $key = array_search($offset, $children);
                if (false === $key) {
                    return array();
                }
                $children = array_slice($children, $key);
            }
        } else {
            $children = $this->dm->getChildren($parent, $filter);
        }

        $result = array();
        foreach ($children as $child) {
            if ($limit !== false || $offset !== false) {
                $child = $this->dm->find(null, "$parentId/$child");
            }

            if (null === $ignoreRole || !$this->publishWorkflowChecker->checkIsPublished($child, $ignoreRole)
                || (null != $class && !($child instanceof $class))
            ) {
                continue;
            }

            $result[] = $child;
            if (false !== $limit) {
                $limit--;
                if (!$limit) {
                    break;
                }
            }
        }

        return $result;
    }

    private function search($current, $reverse = false, $class = null)
    {
        if (empty($current)) {
            return null;
        }

        $path = $this->dm->getUnitOfWork()->getDocumentId($current);
        $node = $this->dm->getPhpcrSession()->getNode($path);
        $parent = $node->getParent();
        $childNames = $parent->getNodeNames();
        if ($reverse) {
            $childNames = array_reverse($childNames->getArrayCopy());
        }

        $check = false;
        foreach ($childNames as $name) {
            if ($check) {
                try {
                    $child = $this->dm->find(null, $parent->getPath().'/'.$name);
                    if ($this->publishWorkflowChecker->checkIsPublished($child)
                        && (null === $class || $child instanceof $class)
                    ) {
                        return $child;
                    }
                } catch (MissingTranslationException $e) {
                    continue;
                }
            }

            if ($node->getName() == $name) {
                $check = true;
            }
        }

        return null;
    }

    public function prev($current)
    {
        return $this->search($current, true);
    }

    public function next($current)
    {
        return $this->search($current);
    }

    public function prevLinkable($current)
    {
        return $this->search($current, true, 'Symfony\Cmf\Component\Routing\RouteAwareInterface');
    }

    public function nextLinkable($current)
    {
        return $this->search($current, false, 'Symfony\Cmf\Component\Routing\RouteAwareInterface');
    }

    public function isPublished($document)
    {
        if (empty($document)) {
            return false;
        }

        return $this->publishWorkflowChecker->checkIsPublished($document, true);
    }

    public function find($path)
    {
        return $this->dm->find(null, $path);
    }

    public function getLocalesFor($document, $includeFallbacks = false)
    {
        try {
            if (empty($document)) {
                return array();
            }

            $locales = $this->dm->getLocalesFor($document, $includeFallbacks);
        } catch (MissingTranslationException $e) {
            $locales = array();
        }

        return $locales;
    }

    public function getName()
    {
        return 'children_extension';
    }
}
