"""Creates the S3 bucket (if needed) for AWS Textract, tags it, and sets
a 1 day expiration rule
"""

import traceback
from pathlib import Path

import boto3
from botocore.exceptions import ClientError
from pydantic_settings import BaseSettings, SettingsConfigDict

AWS_DIR = Path(__file__).resolve().parent

JOB_PREFIX = "textract-jobs/"
TAGS = [{"Key": "AppName", "Value": "curriculum-development-tool"}]


class DeploySettings(BaseSettings):
    AWS_REGION: str = "ca-central-1"
    AWS_S3_BUCKET_BASE: str

    model_config = SettingsConfigDict(
        env_file=str(AWS_DIR / ".env.aws"),
        env_file_encoding="utf-8",
        case_sensitive=True,
        extra="ignore",
    )

def generate_s3_bucket_name(
    base_name: str,
    account_number: str
):
    """
    Generate a pseudo-random S3 bucket name. Uses the provided AWS account number to
    seed the random number generator, ensuring the same random suffix is generated
    each time the function is called for a given account.
    """

    import string
    import random

    random.seed(account_number)

    # bucket names can only contain lowercase letters, numbers, periods and hyphens
    choices = string.ascii_lowercase + string.digits

    bucket_name = base_name.rstrip('-') + '-' + ''.join([random.choice(choices) for i in range(12)])

    if len(bucket_name) > 63:
        raise Exception(f"Generated S3 bucket name \"{bucket_name}\" is too long. Please choose a shorter base name.")
    
    return bucket_name

def main() -> None:
    settings = DeploySettings()

    region, s3_bucket_base = settings.AWS_REGION, settings.AWS_S3_BUCKET_BASE

    sts = boto3.client("sts", region_name=region)

    try:
        caller_identity = sts.get_caller_identity()
    except Exception as e:
        print("Failed to retrieve IAM user/role details of the current user.\n")
        print(*traceback.format_exception_only(e))
        return

    account_number = caller_identity.get('Account')
    user_id = caller_identity.get('UserId')

    if account_number is None or user_id is None:
        raise Exception("Could not obtain a valid AWS account number and/or user ID.")

    print('Running text extraction service AWS deploy script with the following credentials:')
    print(f'Account: {account_number}')
    print(f'User ID: {user_id}\n')
    decision = input("Proceed? [Y/n]: ")

    if decision != 'Y':
        print('\nDeploy canceled.')
        return

    s3 = boto3.client("s3", region_name=region)

    s3_bucket_name = generate_s3_bucket_name(s3_bucket_base, account_number)

    try:
        s3.head_bucket(Bucket=s3_bucket_name)
        print(f"Bucket '{s3_bucket_name}' already exists")
    except ClientError as e:
        if e.response["Error"]["Code"] != "404":
            raise

        s3.create_bucket(
            Bucket=s3_bucket_name,
            CreateBucketConfiguration={"LocationConstraint": region}
        )

        s3.get_waiter("bucket_exists").wait(Bucket=s3_bucket_name)
        print(f"Created bucket '{s3_bucket_name}'")

    s3.put_bucket_tagging(Bucket=s3_bucket_name, Tagging={"TagSet": TAGS})

    s3.put_bucket_lifecycle_configuration(
        Bucket=s3_bucket_name,
        LifecycleConfiguration={"Rules": [{
            "ID": "expire-textract-jobs",
            "Status": "Enabled",
            "Filter": {"Prefix": JOB_PREFIX},
            "Expiration": {"Days": 1},
        }]},
    )

    print(f"Tagged bucket and set 1 day expiration on '{JOB_PREFIX}'")


if __name__ == "__main__":
    main()