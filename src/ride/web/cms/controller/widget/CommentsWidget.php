<?php

namespace ride\web\cms\controller\widget;

use ride\library\cms\content\Content;
use ride\library\cms\node\NodeModel;
use ride\library\orm\OrmManager;
use ride\library\validation\exception\ValidationException;
use ride\library\system\System;

/*
 * Widget to handle comments on a page
 */
class CommentsWidget extends AbstractWidget implements StyleWidget {

    /*
     * Machine name for this widget
     * @var string
     */
    const NAME = 'orm.comments';

     /*
     * Path to the icon of the widget
     * @var string
     */
    const ICON = 'img/cms/widget/comments.png';

    /**
     * Namespace for the templates of this widget
     * @var string
     */
    const TEMPLATE_NAMESPACE = 'cms/widget/orm-comments';

    /**
     * Action to handle the comment form and the comments itself
     * @param \ride\library\orm\OrmManager $orm
     * @param \ride\library\system\System $system
     * @return null
     */
    public function indexAction(OrmManager $orm, System $system) {
        $user = $this->getUser();

        $allowComment = $this->properties->getWidgetProperty('anonymous') || $user;
        $needsApproval = $this->properties->getWidgetProperty('approval');
        $translator = $this->getTranslator();
        $commentModel = $orm->getCommentModel();

        // initialize the comment
        $comment = $commentModel->createEntry();
        $comment->locale = $this->locale;
        if ($user) {
            $comment->name = $user->getDisplayName();
            $comment->email = $user->getEmail();
            $comment->user = $user->getId();
        }
        $comment->client = $system->getClient();

        // set the context of the comment
        $content = $this->getContext('content');
        if ($content && $content instanceof Content) {
            $comment->type = $content->type;
            $comment->entry = $content->data->getId();

            $entry = $content->title;
        } else {
            $node = $this->properties->getNode();

            $comment->type = 'Node';
            $comment->entry = $node->getId();

            $entry = $node->getName($this->locale);
        }

        $templateVariables = array(
            'title' => $this->properties->getWidgetProperty('title.' . $this->locale),
            'form' => null,
            'comments' => null,
        );

        if ($allowComment) {
            // create the comment form
            $form = $this->createFormBuilder($comment);
            $form->addRow('name', 'string', array(
                'label' => $translator->translate('label.name'),
                'validators' => array(
                    'required' => array(),
                ),
            ));
            $form->addRow('email', 'email', array(
                'label' => $translator->translate('label.email'),
            ));
            $form->addRow('body', 'text', array(
                'label' => $translator->translate('label.comment'),
                'validators' => array(
                    'required' => array(),
                ),
            ));
            $form = $form->build();

            // handle the comment form
            if ($form->isSubmitted()) {
                try {
                    $form->validate();

                    $comment = $form->getData();
                    if (!$needsApproval) {
                        $comment->setIsApproved(true);
                    }

                    $commentModel->save($comment);

                    $finish = $this->properties->getWidgetProperty('finish');
                    if ($finish) {
                        $url = $this->getUrl('cms.front.' . $this->properties->getNode()->getRootNodeId() . '.' . $finish . '.' . $this->locale);
                    } else {
                        if ($needsApproval) {
                            $this->addSuccess('success.comment.posted.approval', array('entry' => $entry));
                        } else {
                            $this->addSuccess('success.comment.posted', array('entry' => $entry));
                        }

                        $url = $this->request->getUrl();
                    }

                    $this->response->setRedirect($url);

                    return;
                } catch (ValidationException $exception) {
                    $this->setValidationException($exception, $form);
                }
            }

            $templateVariables['form'] = $form->getView();
        }

        // load the comments
        if (!$this->properties->getWidgetProperty('comments.skip')) {
            $templateVariables['comments'] = $commentModel->getComments($comment->type, $comment->entry, $comment->locale, $needsApproval ? true : null);
        }

        // set everything to the template
        $this->setTemplateView($this->getTemplate(static::TEMPLATE_NAMESPACE . '/default'), $templateVariables);
    }

    /**
     * Gets a preview of the widget instance properties
     * @return string HTML of the preview
     */
    public function getPropertiesPreview() {
        $translator = $this->getTranslator();
        $preview = '';

        $title = $this->properties->getWidgetProperty('title.' . $this->locale);
        if ($title) {
            $preview .= '<strong>' . $translator->translate('label.title') . '</strong>: ' . $title . '<br />';
        }

        $finish = $this->properties->getWidgetProperty('finish');
        if ($finish) {
            $preview .= '<strong>' . $translator->translate('label.node.finish') . '</strong>: ' . $finish . '<br />';
        }

        return $preview;
    }

    /**
     * Action to handle the properties of this widget
     * @param \ride\library\cms\node\NodeModel $nodeModel
     * @return null
     */
    public function propertiesAction(NodeModel $nodeModel) {
        $translator = $this->getTranslator();

        $data = array(
            'title' => $this->properties->getWidgetProperty('title.' . $this->locale),
            'approval' => $this->properties->getWidgetProperty('approval'),
            'anonymous' => $this->properties->getWidgetProperty('anonymous'),
            'finish' => $this->properties->getWidgetProperty('finish'),
            self::PROPERTY_TEMPLATE => $this->getTemplate(static::TEMPLATE_NAMESPACE . '/default'),
        );

        $form = $this->createFormBuilder($data);
        $form->addRow('title', 'string', array(
            'label' => $translator->translate('label.title'),
        ));
        $form->addRow('anonymous', 'option', array(
            'label' => '',
            'description' => $translator->translate('label.comments.anonymous'),
        ));
        $form->addRow('approval', 'option', array(
            'label' => '',
            'description' => $translator->translate('label.comments.approval'),
        ));
        $form->addRow('finish', 'select', array(
            'label' => $translator->translate('label.node.finish'),
            'description' => $translator->translate('label.comments.finish.description'),
            'options' => $this->getNodeList($nodeModel),
        ));
        $form->addRow(self::PROPERTY_TEMPLATE, 'select', array(
            'label' => $translator->translate('label.template'),
            'description' => $translator->translate('label.template.widget.description'),
            'options' => $this->getAvailableTemplates(static::TEMPLATE_NAMESPACE),
            'validators' => array(
                'required' => array(),
            ),
        ));
        $form = $form->build();

        if($form->isSubmitted()) {
            if($this->request->getBodyParameter('cancel')) {
                return false;
            }

            try{
                $form->validate();

                $data = $form->getData();

                $this->properties->setWidgetProperty('title.' . $this->locale, $data['title']);
                $this->properties->setWidgetProperty('approval', $data['approval']);
                $this->properties->setWidgetProperty('anonymous', $data['anonymous']);
                $this->properties->setWidgetProperty('finish', $data['finish']);

                $this->setTemplate($data[self::PROPERTY_TEMPLATE]);

                return true;
            } catch (ValidationException $exception) {
                $this->setValidationException($exception, $form);
            }
        }

        $this->setTemplateView(static::TEMPLATE_NAMESPACE . '/properties', array(
            'form' => $form->getView(),
        ));
    }

    /**
     * Gets the options for the styles
     * @return array Array with the name of the option as key and the
     * translation key as value
     */
    public function getWidgetStyleOptions() {
        return array(
            'container' => 'label.style.container',
            'title' => 'label.style.title',
        );
    }

}
