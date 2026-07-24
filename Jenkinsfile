pipeline {
    agent { label 'windows' }

    options {
        disableConcurrentBuilds()
        timestamps()
    }

    environment {
        LIVE_HOST = 'root@88.99.138.84'
        LIVE_ROOT = '/srv/www/vhosts/crm.anesda.de/public/legacy'
        SSH_KEY = 'C:\\key\\key\\private_openssh'
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
                powershell '''
                    $ErrorActionPreference = 'Stop'
                    $ssh = (Get-Command ssh -ErrorAction Stop).Source
                    $scp = (Get-Command scp -ErrorAction Stop).Source
                    if (-not (Test-Path -LiteralPath $env:SSH_KEY)) {
                        throw "SSH-Schlüssel fehlt: $env:SSH_KEY"
                    }
                    $testArchive = Join-Path $PWD 'dist\\crm-speedphone-tests.zip'
                    Compress-Archive -Path module,tests -DestinationPath $testArchive -Force
                    & $scp -i $env:SSH_KEY -o StrictHostKeyChecking=no `
                        $testArchive "${env:LIVE_HOST}:/tmp/crm-speedphone-tests.zip"
                    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

                    $remote = @'
set -euo pipefail
test_root="/tmp/crm-speedphone-ci-${BUILD_NUMBER}"
[[ "$test_root" == /tmp/crm-speedphone-ci-* ]]
rm -rf -- "$test_root"
mkdir -p "$test_root"
unzip -q /tmp/crm-speedphone-tests.zip -d "$test_root"
cd "$test_root"
php tests/run.php
find module -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
'@
                    $remote = $remote.Replace('${BUILD_NUMBER}', $env:BUILD_NUMBER)
                    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($remote))
                    & $ssh -i $env:SSH_KEY -o StrictHostKeyChecking=no $env:LIVE_HOST `
                        "echo $encoded | base64 -d | bash"
                    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
                '''
            }
        }

        stage('Live-Deployment') {
            when {
                expression {
                    (env.BRANCH_NAME ?: env.GIT_BRANCH ?: '').replaceFirst(/^origin\//, '') == 'main'
                }
            }
            steps {
                powershell '''
                    $ErrorActionPreference = 'Stop'
                    $ssh = (Get-Command ssh -ErrorAction Stop).Source
                    $scp = (Get-Command scp -ErrorAction Stop).Source
                    if (-not (Test-Path -LiteralPath $env:SSH_KEY)) {
                        throw "SSH-Schlüssel fehlt: $env:SSH_KEY"
                    }
                    $zip = Get-ChildItem -LiteralPath dist -Filter 'de.anesda.crmspeedphone-*.zip' |
                        Sort-Object LastWriteTime -Descending |
                        Select-Object -First 1
                    if ($null -eq $zip) { throw 'Installationspaket fehlt.' }

                    & $scp -i $env:SSH_KEY -o StrictHostKeyChecking=no `
                        $zip.FullName "${env:LIVE_HOST}:/tmp/crm-speedphone.zip"
                    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
                    & $scp -i $env:SSH_KEY -o StrictHostKeyChecking=no `
                        'tools/install-live.php' "${env:LIVE_HOST}:/tmp/crm-speedphone-install.php"
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
                    & $ssh -i $env:SSH_KEY -o StrictHostKeyChecking=no $env:LIVE_HOST `
                        "echo $encoded | base64 -d | bash"
                    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
                '''
            }
        }
    }

    post {
        always {
            echo "CRM SpeedPhone: ${currentBuild.currentResult}"
        }
    }
}
