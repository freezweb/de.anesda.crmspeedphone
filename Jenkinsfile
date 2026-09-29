pipeline {
    agent { label 'php82' }

    options {
        disableConcurrentBuilds()
        timestamps()
    }

    environment {
        LIVE_HOST = '88.99.138.84'
        LIVE_ROOT = '/srv/www/vhosts/crm.anesda.de/public/legacy'
    }

    stages {
        stage('Quellcode') {
            steps {
                checkout scm
            }
        }

        stage('Installationspaket') {
            steps {
                sh 'php tools/build.php'
                archiveArtifacts artifacts: 'dist/de.anesda.crmspeedphone-*.zip', fingerprint: true
            }
        }

        stage('PHP-Tests') {
            steps {
                withCredentials([sshUserPrivateKey(
                    credentialsId: 'crm-live-ssh-key',
                    keyFileVariable: 'LIVE_SSH_KEY',
                    usernameVariable: 'LIVE_SSH_USER'
                )]) {
                    sh '''
                        set -eu
                        target="${LIVE_SSH_USER}@${LIVE_HOST}"
                        tar -czf dist/crm-speedphone-tests.tar.gz module tests infra/freepbx
                        scp -i "$LIVE_SSH_KEY" -o StrictHostKeyChecking=no dist/crm-speedphone-tests.tar.gz "$target:/tmp/crm-speedphone-tests.tar.gz"
                        ssh -i "$LIVE_SSH_KEY" -o StrictHostKeyChecking=no "$target" "bash -s -- $BUILD_NUMBER" <<'REMOTE'
set -euo pipefail
build_number="$1"
[[ "$build_number" =~ ^[0-9]+$ ]]
test_root="/tmp/crm-speedphone-ci-${build_number}"
[[ "$test_root" == /tmp/crm-speedphone-ci-* ]]
rm -rf -- "$test_root"
mkdir -p "$test_root"
tar -xzf /tmp/crm-speedphone-tests.tar.gz -C "$test_root"
cd "$test_root"
php tests/run.php
find module -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
REMOTE
                    '''
                }
            }
        }

        stage('Live-Deployment') {
            when {
                expression {
                    (env.BRANCH_NAME ?: env.GIT_BRANCH ?: '').replaceFirst(/^origin\//, '') == 'main'
                }
            }
            steps {
                withCredentials([sshUserPrivateKey(
                    credentialsId: 'crm-live-ssh-key',
                    keyFileVariable: 'LIVE_SSH_KEY',
                    usernameVariable: 'LIVE_SSH_USER'
                )]) {
                    sh '''
                        set -eu
                        target="${LIVE_SSH_USER}@${LIVE_HOST}"
                        archive=$(php -r 'require "module/manifest.php"; echo "dist/de.anesda.crmspeedphone-" . $manifest["version"] . ".zip";')
                        scp -i "$LIVE_SSH_KEY" -o StrictHostKeyChecking=no "$archive" "$target:/tmp/crm-speedphone.zip"
                        scp -i "$LIVE_SSH_KEY" -o StrictHostKeyChecking=no tools/install-live.php "$target:/tmp/crm-speedphone-install.php"
                        ssh -i "$LIVE_SSH_KEY" -o StrictHostKeyChecking=no "$target" "bash -s -- $BUILD_NUMBER" <<'REMOTE'
set -euo pipefail
build_number="$1"
[[ "$build_number" =~ ^[0-9]+$ ]]
legacy=/srv/www/vhosts/crm.anesda.de/public/legacy
deploy=/tmp/crm-speedphone-deploy
archive=/tmp/crm-speedphone.zip
runner=/tmp/crm-speedphone-install.php
[[ "$legacy" == /srv/www/vhosts/crm.anesda.de/public/legacy ]]
[[ "$deploy" == /tmp/crm-speedphone-deploy ]]
[[ -f "$archive" && -f "$runner" ]]
mkdir -p /srv/backups/crm-speedphone
cd "$legacy"
tar -czf "/srv/backups/crm-speedphone/custom-before-jenkins-${build_number}.tar.gz" \
  custom/CRM/SpeedPhone \
  custom/Extension/application/Ext/EntryPointRegistry/crm_speedphone.php \
  custom/Extension/modules/Prospects/Ext/Menus/crm_speedphone.php \
  custom/modules/Prospects \
  custom/modules/Home/Dashlets/CRMSpeedPhoneDashlet
rm -rf -- "$deploy"
mkdir -p "$deploy"
unzip -q "$archive" -d "$deploy"
cp -a "$deploy/copy/custom/." "$legacy/custom/"
mkdir -p "$legacy/custom/modules/Prospects/metadata"
chown www-data:www-data "$legacy/custom/modules/Prospects/metadata"
chown -R www-data:www-data \
  "$legacy/custom/CRM/SpeedPhone" \
  "$legacy/custom/Extension/application/Ext/EntryPointRegistry/crm_speedphone.php" \
  "$legacy/custom/Extension/modules/Prospects/Ext/Menus/crm_speedphone.php" \
  "$legacy/custom/modules/Prospects/views/view.speedphone.php" \
  "$legacy/custom/modules/Home/Dashlets/CRMSpeedPhoneDashlet"
cd "$legacy"
sudo -u www-data php "$runner"
apache2ctl graceful
php -l custom/CRM/SpeedPhone/dialer_setup.php
grep -q crmSpeedPhoneDialerSetup custom/application/Ext/EntryPointRegistry/entry_point_registry.ext.php
curl -fsS -o /dev/null "https://crm.anesda.de/legacy/index.php?entryPoint=crmSpeedPhoneDialerSetup"
REMOTE
                    '''
                }
            }
        }
    }

    post {
        always {
            echo "CRM SpeedPhone: ${currentBuild.currentResult}"
        }
    }
}
