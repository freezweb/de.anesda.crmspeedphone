pipeline {
    agent { label 'windows' }

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
                powershell '''
                    $ErrorActionPreference = 'Stop'
                    & .\\tools\\build.ps1
                    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
                '''
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
                    powershell '''
                        $ErrorActionPreference = 'Stop'
                        $ssh = (Get-Command ssh -ErrorAction Stop).Source
                        $scp = (Get-Command scp -ErrorAction Stop).Source
                        $target = "${env:LIVE_SSH_USER}@${env:LIVE_HOST}"
                        $testArchive = Join-Path $PWD 'dist\\crm-speedphone-tests.tar.gz'
                        & tar.exe -czf $testArchive module tests
                        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
                        & $scp -i $env:LIVE_SSH_KEY -o StrictHostKeyChecking=no `
                            $testArchive "${target}:/tmp/crm-speedphone-tests.tar.gz"
                        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

                        $remote = @'
set -euo pipefail
test_root="/tmp/crm-speedphone-ci-${BUILD_NUMBER}"
[[ "$test_root" == /tmp/crm-speedphone-ci-* ]]
rm -rf -- "$test_root"
mkdir -p "$test_root"
tar -xzf /tmp/crm-speedphone-tests.tar.gz -C "$test_root"
cd "$test_root"
php tests/run.php
find module -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
'@
                        $remote = $remote.Replace('${BUILD_NUMBER}', $env:BUILD_NUMBER)
                        $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($remote))
                        & $ssh -i $env:LIVE_SSH_KEY -o StrictHostKeyChecking=no $target `
                            "echo $encoded | base64 -d | bash"
                        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
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
                    powershell '''
                        $ErrorActionPreference = 'Stop'
                        $ssh = (Get-Command ssh -ErrorAction Stop).Source
                        $scp = (Get-Command scp -ErrorAction Stop).Source
                        $target = "${env:LIVE_SSH_USER}@${env:LIVE_HOST}"
                        $zip = Get-ChildItem -LiteralPath dist -Filter 'de.anesda.crmspeedphone-*.zip' |
                            Sort-Object LastWriteTime -Descending |
                            Select-Object -First 1
                        if ($null -eq $zip) { throw 'Installationspaket fehlt.' }

                        & $scp -i $env:LIVE_SSH_KEY -o StrictHostKeyChecking=no `
                            $zip.FullName "${target}:/tmp/crm-speedphone.zip"
                        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
                        & $scp -i $env:LIVE_SSH_KEY -o StrictHostKeyChecking=no `
                            'tools/install-live.php' "${target}:/tmp/crm-speedphone-install.php"
                        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

                        $remote = @'
set -euo pipefail
legacy=/srv/www/vhosts/crm.anesda.de/public/legacy
deploy=/tmp/crm-speedphone-deploy
archive=/tmp/crm-speedphone.zip
runner=/tmp/crm-speedphone-install.php
[[ "$legacy" == /srv/www/vhosts/crm.anesda.de/public/legacy ]]
[[ "$deploy" == /tmp/crm-speedphone-deploy ]]
[[ -f "$archive" && -f "$runner" ]]
mkdir -p /srv/backups/crm-speedphone
cd "$legacy"
tar -czf "/srv/backups/crm-speedphone/custom-before-jenkins-${BUILD_NUMBER}.tar.gz" \
  custom/CRM/SpeedPhone \
  custom/Extension/application/Ext/EntryPointRegistry/crm_speedphone.php \
  custom/Extension/modules/Prospects/Ext/Menus/crm_speedphone.php \
  custom/modules/Prospects/views/view.speedphone.php \
  custom/modules/Home/Dashlets/CRMSpeedPhoneDashlet
rm -rf -- "$deploy"
mkdir -p "$deploy"
unzip -q "$archive" -d "$deploy"
cp -a "$deploy/copy/custom/." "$legacy/custom/"
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
'@
                        $remote = $remote.Replace('${BUILD_NUMBER}', $env:BUILD_NUMBER)
                        $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($remote))
                        & $ssh -i $env:LIVE_SSH_KEY -o StrictHostKeyChecking=no $target `
                            "echo $encoded | base64 -d | bash"
                        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
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
