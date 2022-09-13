const { Component, Mixin } = Shopware;
import template from './ivy-api-production-test-button.html.twig';

Component.register('ivy-api-production-test-button', {
    template,

    props: ['label'],
    inject: ['ivyApiTest'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    computed: {
        pluginConfig() {
            let $parent = this.$parent;

            while ($parent.actualConfigData === undefined) {
                $parent = $parent.$parent;
            }

            return $parent.actualConfigData.null;
        }
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        check() {
            this.isLoading = true;
            this.pluginConfig["environment"] = 'Production';
            this.ivyApiTest.check(this.pluginConfig).then((res) => {

                if (res.success) {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('ivy-api-test-button.title'),
                        message: this.$tc('ivy-api-test-button.success')
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('ivy-api-test-button.title'),
                        message: res.message.length > 0 ? this.$tc('ivy-api-test-button.errorMissingFields') + res.message : this.$tc('ivy-api-test-button.error')
                    });
                }

                this.isLoading = false;
            });
        }
    }
})
