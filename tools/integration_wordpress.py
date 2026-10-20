import subprocess
import urllib.error
import urllib.request

def run(root,work,state,compose,wait_http):
    compose('up','-d','--wait','wordpress-db')
    compose('up','-d','wordpress')
    base=f"http://127.0.0.1:{state['IT_WP_PORT']}"
    wait_http(base+'/wp-login.php')
    def wp(*args,check=True):
        command=['docker','compose','--project-name','svkms-release-it','--env-file',str(work/'.env'),'-f',str(root/'infrastructure/integration/compose.yaml'),'run','--rm','-T','wordpress-cli','wp',*args]
        result=subprocess.run(command,cwd=root,check=False,capture_output=True,text=True)
        if check and result.returncode:
            # Never stringify the argv: core install contains an ephemeral synthetic password.
            raise RuntimeError('Isolated WP-CLI operation failed: '+args[0]+' (exit '+str(result.returncode)+')')
        return result
    if wp('core','is-installed',check=False).returncode!=0:
        result=wp('core','install','--url='+base,'--title=Synthetic SubsidiaryVault Integration','--admin_user=synthetic-admin','--admin_password='+state['IT_WP_ADMIN_SECRET'],'--admin_email=synthetic@example.invalid','--skip-email')
    wp('plugin','activate','subsidiaryvault-kms')
    result=wp('eval-file','/opt/svkms-wordpress-test.php');print(result.stdout.strip())
    try:response=urllib.request.urlopen(base+'/?rest_route=/subsidiaryvault-kms/v1/connection',timeout=10)
    except urllib.error.HTTPError as e:response=e
    if response.status not in [401,403]:raise RuntimeError('real anonymous WordPress HTTP route was not denied')
    wp('plugin','deactivate','subsidiaryvault-kms')
    if wp('option','get','svkms_connector',check=False).returncode!=0:raise RuntimeError('deactivation unexpectedly removed configuration')
    wp('plugin','uninstall','subsidiaryvault-kms','--skip-delete')
    if wp('option','get','svkms_connector',check=False).returncode==0:raise RuntimeError('uninstall left connector option behind')
    print('Live WordPress HTTP/deactivation/uninstall: 3 assertions passed')
