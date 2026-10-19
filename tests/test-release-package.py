#!/usr/bin/env python3
"""Private Git/ZIP fixtures only. No network, WordPress, provider or publication."""
import hashlib, json, os, pathlib, shutil, subprocess, tempfile, unittest, zipfile

BUILDER = pathlib.Path(__file__).resolve().parents[1] / 'scripts/build-release-package.sh'
GATES = ('plugin-check','wpcs','phpstan','dependency-audit','static-security','attack-regressions','staging-regression')
class PackageTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='wp-provenance-fixture-')
        self.base=pathlib.Path(self.tmp.name);self.repo=self.base/'repo';self.repo.mkdir()
        (self.repo/'scripts').mkdir();shutil.copyfile(BUILDER,self.repo/'scripts/build-release-package.sh')
        (self.repo/'ratesight.php').write_text("<?php\n/**\n * Version: 3.15.5\n */\ndefine( 'RATESIGHT_RELEASE_VERSION', '3.15.5' );\n")
        (self.repo/'uninstall.php').write_text('<?php // retained uninstall policy\n')
        (self.repo/'includes').mkdir();(self.repo/'includes/runtime.php').write_text('<?php // runtime\n')
        (self.repo/'README.md').write_text('development excluded')
        self.git('init','-q');self.git('config','user.email','fixture@invalid.test');self.git('config','user.name','fixture')
        self.commit()
    def tearDown(self):self.tmp.cleanup()
    def git(self,*args):
        return subprocess.check_output(['git','-C',str(self.repo),*args],stderr=subprocess.PIPE).decode().strip()
    def commit(self):
        self.git('add','.');self.git('commit','-qm','private fixture');self.head=self.git('rev-parse','HEAD')
    def call(self,*args,ok=True):
        r=subprocess.run(['bash',str(self.repo/'scripts/build-release-package.sh'),*map(str,args)],capture_output=True,text=True,timeout=10)
        self.assertEqual(r.returncode==0,ok,(r.stdout,r.stderr))
        return json.loads(r.stdout) if ok else r.stderr
    def prepare(self,name='package.zip'):
        p=self.base/name;return p,self.call('prepare','--source-commit',self.head,'--output',p)
    def report(self,p,prepared):
        value={'contract':'ratesight-package-security-v1','sourceCommit':self.head,'sourceTree':prepared['sourceTree'],
               'archiveSha256':prepared['archiveSha256'],'gates':{g:{'status':'pass','evidenceReference':'fixture-only:no-real-security-gate'}for g in GATES},
               'independentReviewReference':'fixture-only:not-human-approval'}
        dest=self.base/'report.json';dest.write_text(json.dumps(value));return dest,value
    def verify(self,p,report,ok=True):
        return self.call('verify','--source-commit',self.head,'--archive',p,'--security-report',report,ok=ok)
    def test_reproducible_exact_git_bytes_allowlist_and_unapproved(self):
        p,a=self.prepare();q,b=self.prepare('second.zip')
        self.assertEqual(p.read_bytes(),q.read_bytes());self.assertEqual(a['archiveSha256'],b['archiveSha256'])
        self.assertFalse(a['humanPublicationApproved']);self.assertIn('UNVERIFIED',a['state'])
        with zipfile.ZipFile(p) as z:
            self.assertEqual(sorted(z.namelist()),['ratesight/includes/runtime.php','ratesight/ratesight.php','ratesight/uninstall.php'])
            self.assertEqual(z.read('ratesight/includes/runtime.php'),b'<?php // runtime\n')
    def test_verified_report_is_not_human_consent(self):
        p,a=self.prepare();r,_=self.report(p,a);v=self.verify(p,r)
        self.assertIn('PUBLICATION_HELD',v['state']);self.assertFalse(v['humanPublicationApproved']);self.assertEqual(v['securityReportPath'],str(r))
    def test_dirty_staged_and_untracked_refused(self):
        for kind in ['tracked','staged','untracked']:
            with self.subTest(kind=kind):
                p=self.repo/('new.php' if kind=='untracked' else 'uninstall.php');old=p.read_bytes()if p.exists()else None
                p.write_text('<?php // modified')
                if kind=='staged':self.git('add',str(p.relative_to(self.repo)))
                self.call('prepare','--source-commit',self.head,'--output',self.base/(kind+'.zip'),ok=False)
                if kind=='staged':self.git('reset','-q','HEAD','--',str(p.relative_to(self.repo)))
                if old is None:p.unlink()
                else:p.write_bytes(old)
    def test_wrong_and_abbreviated_commit_refused(self):
        for commit in ['0'*40,self.head[:7]]:
            self.call('prepare','--source-commit',commit,'--output',self.base/'x.zip',ok=False)
    def test_no_overwrite_and_legacy_refusal(self):
        p,_=self.prepare();before=p.read_bytes()
        self.call('prepare','--source-commit',self.head,'--output',p,ok=False);self.assertEqual(p.read_bytes(),before)
        r=self.call(self.base/'legacy.zip',ok=False);self.assertIn('prepare',r)
    def test_output_inside_source_refused(self):
        self.call('prepare','--source-commit',self.head,'--output',self.repo/'artifact.zip',ok=False)
    def test_source_link_refused(self):
        (self.repo/'includes/link.php').symlink_to('/etc/passwd');self.commit()
        self.call('prepare','--source-commit',self.head,'--output',self.base/'x.zip',ok=False)
    def test_source_gitlink_refused(self):
        self.git('update-index','--add','--cacheinfo','160000,'+self.head+',includes/sub.php')
        self.git('commit','-qm','gitlink');self.head=self.git('rev-parse','HEAD')
        self.call('prepare','--source-commit',self.head,'--output',self.base/'x.zip',ok=False)
    def test_true_runtime_root_symlink_refused(self):
        shutil.rmtree(self.repo/'includes');(self.repo/'includes').symlink_to('/outside-runtime')
        self.commit()
        self.call('prepare','--source-commit',self.head,'--output',self.base/'x.zip',ok=False)
    def test_extensionless_runtime_gitlink_refused(self):
        self.git('update-index','--add','--cacheinfo','160000,'+self.head+',includes/vendor')
        self.git('commit','-qm','extensionless runtime gitlink');self.head=self.git('rev-parse','HEAD')
        self.call('prepare','--source-commit',self.head,'--output',self.base/'x.zip',ok=False)
    def test_newline_path_refused(self):
        (self.repo/'includes/odd\nname.php').write_text('<?php');self.commit()
        self.call('prepare','--source-commit',self.head,'--output',self.base/'x.zip',ok=False)
    def test_version_mismatch_refused(self):
        p=self.repo/'ratesight.php';p.write_text(p.read_text().replace("VERSION', '3.15.5'","VERSION', '3.15.6'"));self.commit()
        self.call('prepare','--source-commit',self.head,'--output',self.base/'x.zip',ok=False)
    def mutate(self,p,kind):
        with zipfile.ZipFile(p)as z:items=[(i,z.read(i))for i in z.infolist()]
        with zipfile.ZipFile(p,'w')as z:
            for i,b in items:
                if kind=='bytes' and i.filename.endswith('runtime.php'):b=b'<?php // attacker'
                if kind=='symlink' and i.filename.endswith('runtime.php'):i.external_attr=0o120777<<16
                z.writestr(i,b)
            if kind in ['extra','traversal','duplicate']:
                name={'extra':'ratesight/extra.php','traversal':'../escape.php','duplicate':'ratesight/uninstall.php'}[kind]
                z.writestr(name,'<?php')
    def test_final_zip_tamper_shapes_refused(self):
        for kind in ['bytes','symlink','extra','traversal','duplicate']:
            with self.subTest(kind=kind):
                p,a=self.prepare(kind+'.zip');r,_=self.report(p,a);self.mutate(p,kind);self.verify(p,r,False)
    def test_report_wrong_bindings_gate_and_approval_refused(self):
        p,a=self.prepare();r,value=self.report(p,a)
        for kind in ['commit','tree','digest','unavailable','approved','missing']:
            v=json.loads(json.dumps(value))
            if kind in ['commit','tree','digest']:v[{'commit':'sourceCommit','tree':'sourceTree','digest':'archiveSha256'}[kind]]='0'*40
            elif kind=='unavailable':v['gates']['phpstan']['status']='unavailable'
            elif kind=='approved':v['approved']=True
            else:del v['gates']['plugin-check']
            r.write_text(json.dumps(v));self.verify(p,r,False)
    def test_report_duplicate_key_and_symlink_refused(self):
        p,a=self.prepare();r,v=self.report(p,a);r.write_text('{"contract":"x","contract":"y"}');self.verify(p,r,False)
        r.unlink();other=self.base/'actual.json';other.write_text(json.dumps(v));r.symlink_to(other);self.verify(p,r,False)
    def test_source_movement_after_prepare_refused(self):
        p,a=self.prepare();r,_=self.report(p,a);old=self.head
        (self.repo/'includes/new.php').write_text('<?php');self.commit()
        self.call('verify','--source-commit',old,'--archive',p,'--security-report',r,ok=False)
if __name__=='__main__':unittest.main()
